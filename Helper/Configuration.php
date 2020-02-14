<?php

namespace MageSuite\S3MediaListing\Helper;

class Configuration extends \Magento\Framework\App\Helper\AbstractHelper
{
    const S3_BUCKET_NAME_XML_PATH = 'system/media_storage_configuration/s3_bucket_name';
    const AWS_REGION_XML_PATH = 'system/media_storage_configuration/aws_region';

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfigInterface
    )
    {
        parent::__construct($context);

        $this->scopeConfig = $scopeConfigInterface;
    }

    public function getS3BucketName()
    {
        return $this->scopeConfig->getValue(self::S3_BUCKET_NAME_XML_PATH);
    }

    public function getAwsRegion()
    {
        return $this->scopeConfig->getValue(self::AWS_REGION_XML_PATH);
    }
}
