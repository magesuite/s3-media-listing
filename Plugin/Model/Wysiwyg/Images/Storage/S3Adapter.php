<?php

namespace MageSuite\S3MediaListing\Plugin\Model\Wysiwyg\Images\Storage;

class S3Adapter
{
    protected \Magento\Framework\Filesystem\Io\File $ioFileSystem;
    protected \Magento\Framework\Filesystem $filesystem;
    protected \Magento\Framework\Data\CollectionFactory $dataCollectionFactory;
    protected \Magento\Cms\Helper\Wysiwyg\Images $cmsWysiwygImages;
    protected \Magento\Framework\View\Asset\Repository $assetRepository;
    protected \Magento\Backend\Model\UrlInterface $backendUrl;
    protected \MageSuite\S3MediaListing\Helper\Configuration $configuration;
    protected array $thumbsCache = [];
    /** @var \Aws\S3\S3Client  */
    protected $s3Client;

    public function __construct(
        \Magento\Framework\Filesystem\Io\File $ioFileSystem,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\Data\CollectionFactory $dataCollectionFactory,
        \Magento\Cms\Helper\Wysiwyg\Images $cmsWysiwygImages,
        \Magento\Framework\View\Asset\Repository $assetRepository,
        \Magento\Backend\Model\UrlInterface $backendUrl,
        \MageSuite\S3MediaListing\Helper\Configuration $configuration
    ) {
        $this->ioFileSystem = $ioFileSystem;
        $this->filesystem = $filesystem;
        $this->dataCollectionFactory = $dataCollectionFactory;
        $this->cmsWysiwygImages = $cmsWysiwygImages;
        $this->assetRepository = $assetRepository;
        $this->backendUrl = $backendUrl;
        $this->configuration = $configuration;
    }

    public function aroundGetDirsCollection(
        \Magento\Cms\Model\Wysiwyg\Images\Storage $subject,
        callable $proceed,
        $path = ''
    ) {
        if (empty($this->getBucketName())) {
            return $proceed($path);
        }

        $isDirectoryAllowed = new \ReflectionMethod(
            \Magento\Cms\Model\Wysiwyg\Images\Storage::class,
            'isDirectoryAllowed'
        );
        $isDirectoryAllowed->setAccessible(true);

        $mediaDirectoryPath = $this->getMediaDirectoryPath();
        $pathInBucket = $this->getPathInBucket($path);
        $s3Client = $this->getS3Client();

        $results = $s3Client->getPaginator('ListObjects', [
            'Bucket' => $this->getBucketName(),
            'Prefix' => $pathInBucket,
            'Delimiter' => '/'
        ]);

        $collection = $this->dataCollectionFactory->create();

        $id = 1;
        foreach ($results as $result) {
            if (empty($result['CommonPrefixes'])) {
                continue;
            }

            foreach ($result['CommonPrefixes'] as $prefix) {
                $directoryName = str_replace($pathInBucket, '', $prefix['Prefix']);

                if (substr($directoryName, 0, 1) == '.') {
                    continue;
                }

                if (!$isDirectoryAllowed->invoke($subject, $mediaDirectoryPath . $pathInBucket . $directoryName)) {
                    continue;
                }

                $item = new \Magento\Framework\DataObject();
                $item->setFilename($mediaDirectoryPath . $pathInBucket . $directoryName);
                $item->setBasename($directoryName);
                $item->setId($id);
                $collection->addItem($item);
                $id++;
            }
        }

        return $collection;
    }

    public function aroundGetFilesCollection(
        \Magento\Cms\Model\Wysiwyg\Images\Storage $subject,
        callable $proceed,
        $path,
        $type = null
    ) {
        $bucketName = $this->getBucketName();

        if (empty($bucketName)) {
            return $proceed($path, $type);
        }

        $mediaDirectoryPath = $this->getMediaDirectoryPath();
        $pathInBucket = $this->getPathInBucket($path);
        $s3Client = $this->getS3Client();

        $results = $s3Client->getPaginator('ListObjects', [
            'Bucket' => $bucketName,
            'Prefix' => $pathInBucket,
            'Delimiter' => '/'
        ]);

        $collection = $this->dataCollectionFactory->create();

        foreach ($results as $result) {
            if (empty($result['Contents'])) {
                continue;
            }

            foreach ($result['Contents'] as $file) {
                $item = new \Magento\Framework\DataObject();

                $fileName = str_replace($pathInBucket, '', $file['Key']);

                if (empty($fileName)) {
                    continue;
                }

                $item->setFilename($mediaDirectoryPath . $pathInBucket . $fileName);
                $item->setBasename($fileName);
                $item->setId($this->cmsWysiwygImages->idEncode($item->getBasename()));
                $item->setName($item->getBasename());
                $item->setShortName($this->cmsWysiwygImages->getShortFilename($item->getBasename()));
                $item->setUrl($this->cmsWysiwygImages->getCurrentUrl() . $item->getBasename());
                $item->setSize($file['Size']);
                $item->setLastModifiedDate(null);

                if (isset($file['LastModified'])) {
                    $item->setLastModifiedDate($file['LastModified']);
                }

                if ($subject->isImage($item->getBasename())) {
                    $thumbUrl = $this->cmsWysiwygImages->getCurrentUrl() . $item->getBasename();
                } else {
                    $thumbUrl = $this->assetRepository->getUrl(
                        \Magento\Cms\Model\Wysiwyg\Images\Storage::THUMB_PLACEHOLDER_PATH_SUFFIX
                    );
                }

                $item->setThumbUrl($thumbUrl);
                $collection->addItem($item);
            }
        }

        $collection = $this->sortByLastModifiedDate($collection);

        return $collection;
    }

    public function getMediaDirectoryPath()
    {
        return $this->filesystem
            ->getDirectoryRead(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA)
            ->getAbsolutePath();
    }

    public function getS3Client()
    {
        if ($this->s3Client == null) {
            $this->s3Client = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region' => $this->configuration->getAwsRegion()
            ]);

            /*
            For testing on local environment replace above with:
            $this->s3Client = new \Aws\S3\S3Client([
                'profile' => 'creativeshop',
                'version' => 'latest',
                'region' => $this->configuration->getAwsRegion()
            ]);
            put credentials into ~/.aws/credentials and change "profile" to one defined in above file
            more info here: https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials_profiles.html
            DO NOT PUSH IT TO REPOSITORY
            after testing revert to original and only then push
            */
        }

        return $this->s3Client;
    }

    /**
     * @param $path
     * @param string $mediaDirectoryPath
     * @return string
     */
    public function getPathInBucket($path): string
    {
        $mediaDirectoryPath = $this->getMediaDirectoryPath();
        $pathInBucket = $path;

        if (strpos($path, $mediaDirectoryPath) !== false) {
            $pathInBucket = rtrim(str_replace($mediaDirectoryPath, '', $path), '/');
        }

        $pathInBucket = !empty($pathInBucket) ? $pathInBucket . '/' : $pathInBucket;

        return $pathInBucket;
    }

    public function getThumbnailUrl($filePath)
    {
        $mediaRootDir = $this->cmsWysiwygImages->getStorageRoot();

        if (strpos($filePath, (string)$mediaRootDir) === 0) {
            $thumbSuffix = \Magento\Cms\Model\Wysiwyg\Images\Storage::THUMBS_DIRECTORY_NAME . '/' . substr($filePath, strlen($mediaRootDir));

            if ($this->thumbExists($thumbSuffix)) {
                $thumbSuffix = substr($mediaRootDir, strlen($mediaRootDir)) . '/' . $thumbSuffix;
                $randomIndex = '?rand=' . time();
                return str_replace('\\', '/', $this->cmsWysiwygImages->getBaseUrl() . $thumbSuffix) . $randomIndex;
            }
        }

        return false;
    }

    public function thumbExists($path)
    {
        $directory = $this->ioFileSystem->dirname($path);

        if (!isset($this->thumbsCache[$directory])) {
            $this->thumbsCache[$directory] = [];

            $s3Client = $this->getS3Client();
            $pathInBucket = $this->getPathInBucket($directory);

            $results = $s3Client->getPaginator('ListObjects', [
                'Bucket' => $this->getBucketName(),
                'Prefix' => $pathInBucket,
                'Delimiter' => '/'
            ]);

            foreach ($results as $result) {
                if (empty($result['Contents'])) {
                    continue;
                }

                foreach ($result['Contents'] as $file) {
                    $this->thumbsCache[$directory][] = $file['Key'];
                }
            }
        }

        return in_array($path, $this->thumbsCache[$directory]);
    }

    /**
     * @return string
     */
    public function getBucketName()
    {
        return $this->configuration->getS3BucketName();
    }

    public function sortByLastModifiedDate($collection)
    {
        $items = $collection->getItems();
        usort($items, [$this, 'compareItemsModifiedDate']);
        $collection->clear();

        foreach ($items as $item) {
            $collection->addItem($item);
        }

        return $collection;
    }

    protected function compareItemsModifiedDate(
        \Magento\Framework\DataObject $itemA,
        \Magento\Framework\DataObject $itemB
    ) {
        return $itemA->getLastModifiedDate() <=> $itemB->getLastModifiedDate();
    }
}
