<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Queue\Processor;

use Magento\Catalog\Model\Product;
use Magento\Downloadable\Api\Data\LinkInterfaceFactory;
use Magento\Downloadable\Api\Data\SampleInterfaceFactory;
use Magento\Downloadable\Api\LinkRepositoryInterface;
use Magento\Downloadable\Api\SampleRepositoryInterface;
use Magento\Downloadable\Model\Link;
use Magento\Downloadable\Model\Link\Builder as LinkBuilder;
use Magento\Downloadable\Model\Product\Type;
use Magento\Downloadable\Model\Sample;
use Magento\Downloadable\Model\Sample\Builder as SampleBuilder;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\MediaStorage\Model\File\Uploader;
use Mohan\ProductQueueSave\Logger\Logger;

/**
 * Replicates the logic of:
 * Magento\Downloadable\Controller\Adminhtml\Product\Initialization\Helper\Plugin\Downloadable::afterInitialize()
 *
 * That plugin reads downloadable data from the HTTP request and sets
 * extension attributes (downloadable_product_links / downloadable_product_samples)
 * on the product before save. Magento's Link\UpdateHandler and Sample\UpdateHandler
 * then persist the changes during productRepository->save().
 *
 * In the queue consumer there is no HTTP request, so this processor does the
 * equivalent job using the raw payload data that the JS already collected.
 *
 * File move strategy: Magento's _moveFileFromTmp() uses rename(), which requires
 * write access to the source directory. Queue consumers may run as a different OS
 * user (e.g. mohan) than the web server (www-data) that owns the tmp directories.
 * To handle this, we do the file move ourselves using copy() (requires only read
 * access to source) and then set status='old' so LinkBuilder skips its own rename.
 */
class DownloadableProcessor
{
    private WriteInterface $mediaWrite;

    public function __construct(
        private readonly LinkBuilder               $linkBuilder,
        private readonly SampleBuilder             $sampleBuilder,
        private readonly LinkInterfaceFactory      $linkFactory,
        private readonly SampleInterfaceFactory    $sampleFactory,
        private readonly LinkRepositoryInterface   $linkRepository,
        private readonly SampleRepositoryInterface $sampleRepository,
        private readonly Link                      $linkModel,
        private readonly Sample                    $sampleModel,
        private readonly Filesystem                $filesystem,
        private readonly Logger                    $logger
    ) {
        $this->mediaWrite = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
    }

    /**
     * Must be called BEFORE productRepository->save() so that the extension
     * attributes are visible to Link\UpdateHandler / Sample\UpdateHandler.
     */
    public function process(Product $product, array $productData): void
    {
        if ($product->getTypeId() !== Type::TYPE_DOWNLOADABLE) {
            return;
        }

        $downloadable = $productData['downloadable'] ?? [];
        if (empty($downloadable)) {
            return;
        }

        $extension = $product->getExtensionAttributes();

        // Index existing links by link_id for file-status comparison
        $existingLinks = [];
        try {
            if ($product->getId()) {
                foreach ($this->linkRepository->getList($product->getSku()) as $link) {
                    $existingLinks[(int) $link->getId()] = $link;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('DownloadableProcessor: could not load existing links: ' . $e->getMessage());
        }

        // Build LinkInterface objects (moves new files out of tmp)
        $links = [];
        foreach ($downloadable['link'] ?? [] as $linkData) {
            if (!$linkData || !empty($linkData['is_delete'])) {
                continue;
            }
            try {
                $linkData = $this->processLinkFileStatus($linkData, $existingLinks);
                $this->logger->info('DownloadableProcessor: building link', [
                    'link_id' => $linkData['link_id'] ?? 'new',
                    'type'    => $linkData['type'] ?? 'unknown',
                    'file'    => $linkData['file'][0]['file'] ?? 'N/A',
                    'status'  => $linkData['file'][0]['status'] ?? 'N/A',
                    'sample'  => $linkData['sample'] ?? 'N/A',
                ]);
                $links[] = $this->linkBuilder->setData($linkData)->build($this->linkFactory->create());
            } catch (\Throwable $e) {
                $this->logger->error(
                    'DownloadableProcessor: link build error [' . get_class($e) . ']: ' . $e->getMessage(),
                    ['link_id' => $linkData['link_id'] ?? 'new']
                );
            }
        }
        $extension->setDownloadableProductLinks($links);

        // Index existing samples by sample_id
        $existingSamples = [];
        try {
            if ($product->getId()) {
                foreach ($this->sampleRepository->getList($product->getSku()) as $sample) {
                    $existingSamples[(int) $sample->getId()] = $sample;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('DownloadableProcessor: could not load existing samples: ' . $e->getMessage());
        }

        // Build SampleInterface objects
        $samples = [];
        foreach ($downloadable['sample'] ?? [] as $sampleData) {
            if (!$sampleData || !empty($sampleData['is_delete'])) {
                continue;
            }
            try {
                $sampleData = $this->processSampleFileStatus($sampleData, $existingSamples);
                $samples[]  = $this->sampleBuilder->setData($sampleData)->build($this->sampleFactory->create());
            } catch (\Throwable $e) {
                $this->logger->error(
                    'DownloadableProcessor: sample build error [' . get_class($e) . ']: ' . $e->getMessage(),
                    ['sample_id' => $sampleData['sample_id'] ?? 'new']
                );
            }
        }
        $extension->setDownloadableProductSamples($samples);

        $product->setExtensionAttributes($extension);

        if ($product->getLinksPurchasedSeparately()) {
            $product->setTypeHasRequiredOptions(true)->setRequiredOptions(true);
        } else {
            $product->setTypeHasRequiredOptions(false)->setRequiredOptions(false);
        }

        $this->logger->info('DownloadableProcessor: extension attributes set', [
            'product_id' => $product->getId(),
            'links'      => count($links),
            'samples'    => count($samples),
        ]);
    }

    private function processLinkFileStatus(array $linkData, array $existingLinks): array
    {
        $linkId   = isset($linkData['link_id']) ? (int) $linkData['link_id'] : null;
        $existing = $linkId ? ($existingLinks[$linkId] ?? null) : null;

        $linkData           = $this->setFileStatus(
            $linkData,
            $existing?->getLinkFile(),
            $this->linkModel->getBaseTmpPath(),
            $this->linkModel->getBasePath()
        );
        $linkData['sample'] = $this->setFileStatus(
            $linkData['sample'] ?? [],
            $existing?->getSampleFile(),
            $this->linkModel->getBaseSampleTmpPath(),
            $this->linkModel->getBaseSamplePath()
        );

        return $linkData;
    }

    private function processSampleFileStatus(array $sampleData, array $existingSamples): array
    {
        $sampleId = isset($sampleData['sample_id']) ? (int) $sampleData['sample_id'] : null;
        $existing = $sampleId ? ($existingSamples[$sampleId] ?? null) : null;

        return $this->setFileStatus(
            $sampleData,
            $existing?->getSampleFile(),
            $this->sampleModel->getBaseTmpPath(),
            $this->sampleModel->getBasePath()
        );
    }

    /**
     * Ensures the file is at its permanent location and sets status='old' so that
     * LinkBuilder/SampleBuilder skip their internal rename() call.
     *
     * If the file is already at the permanent location (same path as DB, or not in tmp),
     * we just mark it 'old'. If it IS in tmp, we copy it ourselves using PHP copy()
     * which only requires read access to the source directory — rename() fails when the
     * queue consumer runs as a different OS user than the web server (www-data).
     */
    private function setFileStatus(array $data, ?string $existingFile, string $baseTmpPath, string $basePath): array
    {
        if (!isset($data['type']) || $data['type'] !== 'file' || !isset($data['file'][0]['file'])) {
            return $data;
        }

        $filePath = $data['file'][0]['file'];

        // Already at permanent location (same path as what's in the DB)
        if ($filePath === $existingFile) {
            $data['file'][0]['status'] = 'old';
            return $data;
        }

        $tmpRelPath = rtrim($baseTmpPath, '/') . '/' . ltrim($filePath, '/');

        if (!$this->mediaWrite->isFile($tmpRelPath)) {
            // Not in tmp — already moved to permanent by a prior save.
            $data['file'][0]['status'] = 'old';
            return $data;
        }

        // File IS in tmp. Copy to permanent ourselves to avoid rename() permission failures.
        $srcAbs = $this->mediaWrite->getAbsolutePath($tmpRelPath);

        // Compute destination filename (handles conflicts the same way Uploader does)
        $destRelFile = dirname($filePath) . '/'
            . Uploader::getNewFileName(
                $this->mediaWrite->getAbsolutePath(rtrim($basePath, '/') . '/' . ltrim($filePath, '/'))
            );
        $destAbs = $this->mediaWrite->getAbsolutePath(
            rtrim($basePath, '/') . '/' . ltrim($destRelFile, '/')
        );

        $destDir = dirname($destAbs);
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }

        if (@copy($srcAbs, $destAbs)) {
            $data['file'][0]['file']   = $destRelFile;
            $data['file'][0]['status'] = 'old';
            $this->logger->info('DownloadableProcessor: copied file to permanent storage', [
                'src' => $tmpRelPath,
                'dst' => rtrim($basePath, '/') . '/' . ltrim($destRelFile, '/'),
            ]);
        } else {
            // copy() failed — fall through to rename() in LinkBuilder (last resort)
            $data['file'][0]['status'] = 'new';
            $this->logger->warning('DownloadableProcessor: copy() failed, falling back to rename', [
                'src' => $srcAbs,
                'dst' => $destAbs,
            ]);
        }

        return $data;
    }
}
