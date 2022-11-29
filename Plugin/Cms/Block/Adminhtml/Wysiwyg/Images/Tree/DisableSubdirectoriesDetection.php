<?php

namespace MageSuite\S3MediaListing\Plugin\Cms\Block\Adminhtml\Wysiwyg\Images\Tree;

// Detecting whether directory has subdirectories introduced for media files tree in
// Magento >= 2.4.0 requires a lot of additional requests done to S3 API = performance hit
// This plugin disables calculation for improved performance
// Downside is that all folders in tree are displayed with arrow to show subfolders even
// when in reality they don't have any subfolders
class DisableSubdirectoriesDetection
{
    /**
     * @var \Magento\Cms\Helper\Wysiwyg\Images
     */
    protected $cmsWysiwygImages;

    /**
     * @var \Magento\Framework\Registry
     */
    protected $registry;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    protected $serializer;

    public function __construct(
        \Magento\Cms\Helper\Wysiwyg\Images $cmsWysiwygImages,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Serialize\Serializer\Json $serializer
    ) {
        $this->cmsWysiwygImages = $cmsWysiwygImages;
        $this->registry = $registry;
        $this->serializer = $serializer;
    }

    public function aroundGetTreeJson(
        \Magento\Cms\Block\Adminhtml\Wysiwyg\Images\Tree $subject,
        callable $proceed
    ) {
        $storageRoot = $this->cmsWysiwygImages->getStorageRoot();

        $currentPath = $this->cmsWysiwygImages->getCurrentPath();

        $collection = $this->registry
            ->registry('storage')
            ->getDirsCollection($currentPath);

        $jsonArray = [];

        foreach ($collection as $item) {
            $data = [
                'text' => $this->cmsWysiwygImages->getShortFilename($item->getBasename(), 20),
                'id' => $this->cmsWysiwygImages->convertPathToId($item->getFilename()),
                'path' => substr($item->getFilename(), strlen($storageRoot)),
                'cls' => 'folder',
                'children' => true
            ];

            $jsonArray[] = $data;
        }

        return $this->serializer->serialize($jsonArray);
    }
}
