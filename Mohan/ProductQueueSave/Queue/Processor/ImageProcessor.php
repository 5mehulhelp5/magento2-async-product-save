<?php
/**
 * Mohan_ProductQueueSave
 */

declare(strict_types=1);

namespace Mohan\ProductQueueSave\Queue\Processor;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\Api\Data\ImageContentInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\File\Mime;
use Magento\Framework\Filesystem;
use Mohan\ProductQueueSave\Logger\Logger;

/**
 * Builds media_gallery entries (with base64 'content') so that
 * MediaGalleryProcessor::processEntries() can process them.
 *
 * ── Why we DON'T use Gallery\Processor::addImage() here ─────────────────────
 *
 * The natural approach — calling Gallery\Processor::addImage() on each source
 * file — builds a gallery entry with a 'content' field AND moves the source to
 * a dispersion-based tmp path (e.g. tmp/catalog/product/p/q/pqss_xxx.jpg).
 *
 * The problem: when productRepository::save() runs, processEntries() takes the
 * base64 content from that entry and calls processImageContent(), which tries
 * to save the decoded file to THIS EXACT SAME dispersion path. With
 * setAllowRenameFiles(true), the uploader renames the file (pqss_xxx_1.jpg),
 * recomputes the dispersion hash for the NEW name, but NEVER creates the new
 * dispersion directory — the silent move failure leaves _uploadedFileName empty
 * or pointing to a non-existent path, so inner addImage() throws:
 *   "The image doesn't exist."
 *
 * ── What we do instead ──────────────────────────────────────────────────────
 *
 *  1. Copy each source file to a FLAT staged path:
 *       pub/media/tmp/catalog/product/pqss_xxx_<basename>.jpg
 *     (no dispersion subdirectory — only in the root of tmp/catalog/product/).
 *
 *  2. Read the staged file and base64-encode its content.
 *
 *  3. Build a gallery array entry that includes the required 'content' field:
 *       content => [ data => [ name, base64_encoded_data, type ] ]
 *
 *  4. Add this entry to the product's media_gallery.
 *     The 'content' field satisfies processEntries():
 *       if (!isset($newEntry['content'])) throw InputException('...invalid...')
 *
 *  5. delete the flat staged file (image content is now in the base64 payload).
 *
 * When processNewMediaGalleryEntry() later calls processImageContent(), it
 * decodes the base64 and saves via the uploader to tmp/catalog/product/<name>.
 * The dispersion path for our pqss_ filename is unique and the directory is
 * freshly created — NO collision, NO rename bug, inner addImage() succeeds.
 */
class ImageProcessor
{
    public function __construct(
        private readonly MediaConfig $mediaConfig,
        private readonly Filesystem  $filesystem,
        private readonly Mime        $mime,
        private readonly Logger      $logger,
    ) {}

    /**
     * @param ProductInterface|Product $product
     * @param array $imageData  ['images' => [...], 'image_roles' => [...]]
     */
    public function process(ProductInterface $product, array $imageData): void
    {
        $images     = $imageData['images']     ?? [];
        $imageRoles = $imageData['image_roles'] ?? [];

        if (empty($images) && empty($imageRoles)) {
            return;
        }

        /** @var Product $product */
        $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);

        // Preserve any gallery state already on the product (e.g. removal entries).
        $currentGallery = $product->getData('media_gallery') ?? ['images' => []];
        $galleryImages  = $currentGallery['images'] ?? [];
        $position       = $this->getMaxPosition($galleryImages) + 1;

        foreach ($images as $imageEntry) {
            if (empty($imageEntry['file'])) {
                continue;
            }

            $file      = $imageEntry['file'];
            $isRemoved = !empty($imageEntry['removed']);

            // Removals are handled separately in ProductSaveConsumer; skip here.
            if ($isRemoved) {
                continue;
            }

            // Skip images that are already persisted in the DB. They have a
            // value_id and do not need to be re-encoded and re-inserted.
            // Processing them would create duplicate gallery records on every save.
            if (!empty($imageEntry['value_id'])) {
                continue;
            }

            // ── Locate the source file ────────────────────────────────────────
            $sourceAbsolutePath = $this->getSourceAbsolutePath($file);
            if (!$sourceAbsolutePath) {
                $this->logger->error("ImageProcessor: Source file not found: $file");
                continue;
            }

            // ── Determine image roles ─────────────────────────────────────────
            $roles = [];
            foreach ($imageRoles as $role => $roleFile) {
                if ($roleFile === $file) {
                    $roles[] = $role;
                }
            }

            // ── Stage to a FLAT tmp file (no dispersion subdirectory) ─────────
            //
            // We stage to tmp/catalog/product/pqss_xxx.jpg (ROOT level, no subdir).
            // When processImageContent() later saves from the base64 content, it
            // uses an uploader with dispersion enabled. Because our staged file is
            // at the flat root and NOT inside a /p/q/ subdirectory, there is NO
            // filename collision → no rename → no missing mkdir → no silent fail.
            $basename      = preg_replace('/\.tmp$/', '', basename($file));
            $cleanBasename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename);
            $stagedName    = 'pqss_' . uniqid() . '_' . $cleanBasename;
            $stagedRelPath = 'tmp/catalog/product/' . $stagedName;
            $stagedAbsPath = $mediaDirectory->getAbsolutePath($stagedRelPath);

            $mediaDirectory->create('tmp/catalog/product');

            if (!copy($sourceAbsolutePath, $stagedAbsPath)) {
                $this->logger->error("ImageProcessor: Failed to stage", [
                    'source' => $sourceAbsolutePath,
                    'staged' => $stagedAbsPath,
                ]);
                continue;
            }
            chmod($stagedAbsPath, 0644);

            // ── Read content and build base64 payload ─────────────────────────
            try {
                $imageMimeType = $this->mime->getMimeType($stagedAbsPath);
                $imageContent  = $mediaDirectory->readFile($stagedRelPath);
                $imageBase64   = base64_encode($imageContent);
                // imageName MUST include the extension (e.g. 'pqss_xxx_gcds12516_47.jpg').
                // processImageContent() passes getName() as the 'name' field in
                // processFileAttributes(). Uploader::_validateFile() checks the extension
                // of that name — if it's empty (no extension), it throws:
                //   "Disallowed file type."  (caught silently, returns null → isFile fails)
                // Including the extension lets _validateFile() pass, and getFileName()
                // detects it and uses it directly (no double-extension added).
                $imageName = $stagedName; // WITH extension, e.g. 'pqss_xxx_gcds12516_47.jpg'
            } catch (\Exception $e) {
                @unlink($stagedAbsPath);
                $this->logger->error("ImageProcessor: Failed to read staged file", [
                    'staged'  => $stagedAbsPath,
                    'message' => $e->getMessage(),
                ]);
                continue;
            }

            // ── Delete the staged file ────────────────────────────────────────
            // processImageContent() decodes the base64 payload independently — it
            // does NOT use the staged file. Remove it to avoid tmp accumulation.
            @unlink($stagedAbsPath);

            // ── Build the gallery entry with 'content' ────────────────────────
            //
            // processEntries() immediately throws if 'content' is missing:
            //   if (!isset($newEntry['content'])) {
            //       throw new InputException(__('The image content is invalid...'));
            //   }
            //
            // 'types' tells processNewMediaGalleryEntry() which image role
            // attributes (image, small_image, thumbnail, swatch_image) to assign
            // to the product via the inner Gallery\Processor::addImage() call.
            $newEntry = [
                'file'       => '/' . $stagedName,  // used by determineImageRoles() only
                'media_type' => 'image',
                'label'      => $imageEntry['label']    ?? '',
                'position'   => $imageEntry['position'] ?? $position,
                'disabled'   => 0,
                'types'      => $roles,
                'content'    => [
                    'data' => [
                        ImageContentInterface::NAME                => $imageName,
                        ImageContentInterface::BASE64_ENCODED_DATA => $imageBase64,
                        ImageContentInterface::TYPE                => $imageMimeType,
                    ],
                ],
            ];

            $galleryImages[] = $newEntry;

            $this->logger->info("ImageProcessor: Built gallery entry with content", [
                'source' => $sourceAbsolutePath,
                'name'   => $imageName,
                'roles'  => $roles,
            ]);

            $position++;
        }

        $product->setData('media_gallery', ['images' => $galleryImages]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getMaxPosition(array $galleryImages): int
    {
        $max = 0;
        foreach ($galleryImages as $img) {
            $pos = (int) ($img['position'] ?? 0);
            if ($pos > $max) {
                $max = $pos;
            }
        }
        return $max;
    }

    /**
     * Resolve the absolute filesystem path of a source image.
     *
     * @param string $file  e.g. "/g/c/filename.jpg.tmp" from the form
     * @return string|null  absolute path to a readable, non-empty image file
     */
    private function getSourceAbsolutePath(string $file): ?string
    {
        $mediaDir     = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $absMediaPath = rtrim($mediaDir->getAbsolutePath(), '/');
        $cleanFile    = preg_replace('/\.tmp$/', '', $file);

        $potentialPaths = [
            $absMediaPath . '/tmp/catalog/product' . $file,      // with .tmp suffix (primary)
            $absMediaPath . '/catalog/product'     . $file,      // final location with .tmp
            $absMediaPath . '/tmp/catalog/product' . $cleanFile, // without .tmp (fallback)
            $absMediaPath . '/catalog/product'     . $cleanFile, // already in final location
        ];

        foreach ($potentialPaths as $path) {
            if (!file_exists($path) || !is_file($path) || filesize($path) === 0) {
                continue;
            }
            $realPath = realpath($path);
            if ($realPath && str_starts_with($realPath, $absMediaPath . '/')) {
                return $realPath;
            }
        }

        return null;
    }
}
