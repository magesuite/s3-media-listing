<?php

namespace MageSuite\S3MediaListing\Plugin\Model\Wysiwyg\Images\Storage;

class S3Adapter
{
    /**
     * @var \Magento\Framework\Filesystem
     */
    protected $filesystem;

    /**
     * @var \Magento\Framework\Data\CollectionFactory
     */
    protected $dataCollectionFactory;

    /**
     * @var \Magento\Cms\Helper\Wysiwyg\Images
     */
    protected $cmsWysiwygImages;

    /**
     * @var \Magento\Framework\View\Asset\Repository
     */
    protected $assetRepository;

    public function __construct(
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\Data\CollectionFactory $dataCollectionFactory,
        \Magento\Cms\Helper\Wysiwyg\Images $cmsWysiwygImages,
        \Magento\Framework\View\Asset\Repository $assetRepository
    )
    {
        $this->filesystem = $filesystem;
        $this->dataCollectionFactory = $dataCollectionFactory;
        $this->cmsWysiwygImages = $cmsWysiwygImages;
        $this->assetRepository = $assetRepository;
    }

    public function aroundGetDirsCollection(\Magento\Cms\Model\Wysiwyg\Images\Storage $subject, callable $proceed, $path = '')
    {
        $originalResult = $proceed($path);

        $mediaDirectoryPath = $this->getMediaDirectoryPath();
        $pathInBucket = $this->getPathInBucket($path, $mediaDirectoryPath);

        $s3Client = $this->getS3Client();

        $results = $s3Client->getPaginator('ListObjects', [
            'Bucket' => 'cs-magesuite-dev-media',
            'Prefix' => $pathInBucket,
            'Delimiter' => '/'
        ]);

        $collection = $this->dataCollectionFactory->create();

        $id = 1;
        foreach ($results as $result) {
            if(!isset($result['CommonPrefixes']) or $result['CommonPrefixes'] == null) {
                continue;
            }

            foreach($result['CommonPrefixes'] as $prefix) {
                $directoryName = str_replace($pathInBucket, '', $prefix['Prefix']);

                $item = new \Magento\Framework\DataObject();
                $item->setFilename($mediaDirectoryPath.$pathInBucket.$directoryName);
                $item->setBasename($directoryName);
                $item->setId($id);

                $collection->addItem($item);

                $id++;
            }
        }


        return $collection;
    }

    public function aroundGetFilesCollection(\Magento\Cms\Model\Wysiwyg\Images\Storage $subject, callable $proceed, $path, $type = null)
    {
        $mediaDirectoryPath = $this->getMediaDirectoryPath();
        $pathInBucket = $this->getPathInBucket($path);

        $s3Client = $this->getS3Client();

        $results = $s3Client->getPaginator('ListObjects', [
            'Bucket' => 'cs-magesuite-dev-media',
            'Prefix' => $pathInBucket,
            'Delimiter' => '/'
        ]);

        $collection = $this->dataCollectionFactory->create();

        foreach ($results as $result) {
            if(empty($result['Contents'])) {
                continue;
            }

            foreach($result['Contents'] as $file) {
                $item = new \Magento\Framework\DataObject();

                $fileName = str_replace($pathInBucket, '', $file['Key']);

                if(empty($fileName)) {
                    continue;
                }

                $item->setFilename($mediaDirectoryPath.$pathInBucket.$fileName);
                $item->setBasename($fileName);
                $item->setId($this->cmsWysiwygImages->idEncode($item->getBasename()));
                $item->setName($item->getBasename());
                $item->setShortName($this->cmsWysiwygImages->getShortFilename($item->getBasename()));
                $item->setUrl($this->cmsWysiwygImages->getCurrentUrl() . $item->getBasename());
                $item->setSize($file['Size']);
//                $item->setMimeType(\mime_content_type($item->getFilename()));

                if ($subject->isImage($item->getBasename())) {
    //                $thumbUrl = $subject->getThumbnailUrl($item->getFilename(), true);
    //                // generate thumbnail "on the fly" if it does not exists
    //                if (!$thumbUrl) {
    //                    $thumbUrl = $this->_backendUrl->getUrl('cms/*/thumbnail', ['file' => $item->getId()]);
    //                }
                    $thumbUrl = $this->assetRepository->getUrl(\Magento\Cms\Model\Wysiwyg\Images\Storage::THUMB_PLACEHOLDER_PATH_SUFFIX);
                } else {
                    $thumbUrl = $this->assetRepository->getUrl(\Magento\Cms\Model\Wysiwyg\Images\Storage::THUMB_PLACEHOLDER_PATH_SUFFIX);
                }

                $item->setThumbUrl($thumbUrl);

                $collection->addItem($item);
            }
        }

        return $collection;

        $originalCollection = $proceed($path, $type);

//        // prepare items
//        foreach ($collection as $item) {
//            $item->setId($this->_cmsWysiwygImages->idEncode($item->getBasename()));
//            $item->setName($item->getBasename());
//            $item->setShortName($this->_cmsWysiwygImages->getShortFilename($item->getBasename()));
//            $item->setUrl($this->_cmsWysiwygImages->getCurrentUrl() . $item->getBasename());
//            $itemStats = $this->file->stat($item->getFilename());
//            $item->setSize($itemStats['size']);
//            $item->setMimeType(\mime_content_type($item->getFilename()));
//
//            if ($subject->isImage($item->getBasename())) {
//                $thumbUrl = $subject->getThumbnailUrl($item->getFilename(), true);
//                // generate thumbnail "on the fly" if it does not exists
//                if (!$thumbUrl) {
//                    $thumbUrl = $this->_backendUrl->getUrl('cms/*/thumbnail', ['file' => $item->getId()]);
//                }
//            } else {
//                $thumbUrl = $this->_assetRepo->getUrl(self::THUMB_PLACEHOLDER_PATH_SUFFIX);
//            }
//
//            $item->setThumbUrl($thumbUrl);
//        }

        return $originalCollection;
    }

    public function getMediaDirectoryPath() {
        return $this->filesystem
            ->getDirectoryRead(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA)
            ->getAbsolutePath();
    }

    public function getS3Client() {
        $profile = 'default';
        $path = '/home/magento2/.aws/credentials';

        $provider = \Aws\Credentials\CredentialProvider::ini($profile, $path);
        $provider = \Aws\Credentials\CredentialProvider::memoize($provider);

        return new \Aws\S3\S3Client([
            'version' => 'latest',
            'region' => 'eu-central-1',
            'credentials' => $provider
        ]);
    }

    /**
     * @param $path
     * @param string $mediaDirectoryPath
     * @return string
     */
    public function getPathInBucket($path): string
    {
        $mediaDirectoryPath = $this->getMediaDirectoryPath();

        if (strpos($path, $mediaDirectoryPath) !== false) {
            $pathInBucket = rtrim(str_replace($mediaDirectoryPath, '', $path), '/');

            $pathInBucket = !empty($pathInBucket) ? $pathInBucket . '/' : $pathInBucket;
        }
        return $pathInBucket;
    }


}