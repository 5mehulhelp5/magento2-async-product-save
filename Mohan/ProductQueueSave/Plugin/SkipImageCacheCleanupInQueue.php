<?php
declare(strict_types=1);

namespace Mohan\ProductQueueSave\Plugin;

use Magento\Catalog\Model\Product\Image\RemoveDeletedImagesFromCache;

class SkipImageCacheCleanupInQueue
{
    public function aroundRemoveDeletedImagesFromCache(
        RemoveDeletedImagesFromCache $subject,
        callable $proceed,
        array $images
    ): void {
        try {
            $proceed($images);
        } catch (\InvalidArgumentException $e) {
            // "Required parameter 'theme_dir' was not passed"
            // The theme is not fully initialized in the queue CLI context.
            // Safe to skip cache cleanup; orphaned cached images won't break things.
        }
    }
}
