<?php

namespace BAUERGROUPRemoveBackground\Subscribers;

use Enlight\Event\SubscriberInterface;
use Shopware\Components\Plugin\CachedConfigReader;

use Shopware\Models\Media\Media;
use Shopware\Models\Media\Repository as MediaRepository;

class BAUERGROUPRemoveBackgroundSubscriber implements SubscriberInterface
{
   private $config;

   private $processingEnabled;
   private $apiKey;
   private $albumNames;
   private $previewQuality;
   private $filterRegEx;

   public function __construct(CachedConfigReader $configReader, $pluginName)
   {
       $this->config = $configReader->getByPluginName($pluginName);
       
       $this->processingEnabled = $this->config["Enabled"];
       $this->apiKey = $this->config["APIKey"];
       $this->albumNames = explode(';', $this->config["AlbumNames"]);  
       $this->previewQuality = $this->config["PreviewImageQuality"];
       $this->filterRegEx = $this->config["FilterRegEx"];
   }

   public static function getSubscribedEvents()
   {		
       return [			
        'Shopware\Models\Media\Media::postPersist' => 'onMediaPostPersist',        
       ];
   }

   public function onMediaPostPersist(\Enlight_Event_EventArgs $args)
   {     
        Shopware()->Pluginlogger()->info('BAUERGROUPRemoveBackground: MEDIA PROCESSING - BEGIN');

        $mediaObject = $args->get('entity');

        $mediaPath = $mediaObject->getPath();
        $mediaPathInfo = pathinfo($mediaPath);
        $mediaAlbum = $mediaObject->getAlbum();

        Shopware()->Pluginlogger()->info('BAUERGROUPRemoveBackground: Processing File -> ' . $mediaPathInfo['basename'] . ' within Album ' . $mediaAlbum->getName());

        //Processing Enabled
        if ($this->processingEnabled == false)
        {
            Shopware()->Pluginlogger()->warning('BAUERGROUPRemoveBackground: Processing of Media during Upload is disabled. Skipping background removal of this Image.');
            Shopware()->Pluginlogger()->info('BAUERGROUPRemoveBackground: MEDIA PROCESSING - END');
            return;
        }

        //Validate API Key
        if (empty($this->apiKey))
        {
            Shopware()->Pluginlogger()->error('BAUERGROUPRemoveBackground: API-Key is not set. Unable to Process Media.');
            Shopware()->Pluginlogger()->info('BAUERGROUPRemoveBackground: MEDIA PROCESSING - END');
            return;
        }

        //Only Images Supported        
        if (!in_array(strtolower($mediaPathInfo['extension']), ['jpg', 'jpeg', 'png']))
        {
            Shopware()->Pluginlogger()->info('BAUERGROUPRemoveBackground: Not Processing this Image. Unsupported File Type -> ' . $fileExtension);
            Shopware()->Pluginlogger()->info('BAUERGROUPRemoveBackground: MEDIA PROCESSING - END');
            return;
        }

        //Check for Albums
        if (!in_array($mediaAlbum->getName(), $this->albumNames))
        {          
            Shopware()->Pluginlogger()->info('BAUERGROUPRemoveBackground: Skipping this Image, because it is in a excluded Album with Name ' . $mediaAlbum->getName());
            Shopware()->Pluginlogger()->info('BAUERGROUPRemoveBackground: MEDIA PROCESSING - END');
            return;            
        }

        //Check for Regex Filter
        if ( ($this->filterRegEx != '') && (!preg_match($this->filterRegEx, $mediaPathInfo['basename'])) )
        {          
            Shopware()->Pluginlogger()->info('BAUERGROUPRemoveBackground: Skipping this Image, because it is not matching the RegEx Filter ' . $this->filterRegEx);
            Shopware()->Pluginlogger()->info('BAUERGROUPRemoveBackground: MEDIA PROCESSING - END');
            return;            
        }

        //Account Informations
        $removeBGAccount = $this->GetAccountInformation();
        if (is_null($removeBGAccount))
        {          
            Shopware()->Pluginlogger()->error('BAUERGROUPRemoveBackground: Skipping this Image, because Account Information cannot be retrieved.');
            Shopware()->Pluginlogger()->info('BAUERGROUPRemoveBackground: MEDIA PROCESSING - END');
            return;            
        }
        $credits = $removeBGAccount["data"]["attributes"] ["credits"]["total"];
        $previews = $removeBGAccount["data"]["attributes"] ["api"]["free_calls"];

        //Check Balance
        if ($this->previewQuality == true ? $previews <= 0 : $credits <= 0)
        {
            Shopware()->Pluginlogger()->warning('BAUERGROUPRemoveBackground: Unable to Process Media. Leaving this Image as it is. No Credits remaining in your account. You need to buy Credits for further Image Processing.'
            . ' Account Information -> Credits Remaining:' . $credits . ' / Previews Remaining: ' . $previews);
            Shopware()->Pluginlogger()->info('BAUERGROUPRemoveBackground: MEDIA PROCESSING - END');
            return;
        }

        Shopware()->Pluginlogger()->info('BAUERGROUPRemoveBackground: Removing Background for File ' . $mediaPath);
        Shopware()->Pluginlogger()->info('BAUERGROUPRemoveBackground: remove.bg Account Information -> Credits Remaining:' . $credits . ' / Previews Remaining: ' . $previews);

        //Process Media
        $this->RemoveBackground($mediaPath);        
        
        Shopware()->Pluginlogger()->info('BAUERGROUPRemoveBackground: MEDIA PROCESSING - END');
   } //onMediaPostPersist

   private function GetAccountInformation()
    {
        $client = curl_init();

        curl_setopt($client, CURLOPT_URL, 'https://api.remove.bg/v1.0/account');        
        curl_setopt($client, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($client, CURLOPT_HTTPHEADER, array(
            'X-Api-Key: ' . $this->apiKey,
            'User-Agent: BAUERGROUP RemoveBG Client for Shopware 5'
        ));

        $jsonResult = curl_exec($client);

        $requestInfo = curl_getinfo($client, CURLINFO_HTTP_CODE);        
        if ($requestInfo <> 200)
        {
            Shopware()->Pluginlogger()->error('BAUERGROUPRemoveBackground: Error at using remove.bg Service. Cause -> Return Code:' . $requestInfo . ' / Return Body: ' . $jsonResult);                       
            return null;
        }

        return json_decode($jsonResult, true);
    }

    private function RemoveBackground($mediaPath)
    {        
        //Process Image
        $mediaService = Shopware()->Container()->get('shopware_media.media_service');

        //Configuration
        $mediaPathInfo = pathinfo($mediaPath);
        $mediaType = in_array(strtolower($mediaPathInfo['extension']), ['jpg', 'jpeg']) ? "jpg" : "png";
        $mediaSize = $this->previewQuality == true ? "preview" : "full";
        $mediaBGColor = "FFFFFF";

        //Create Temporary Files
        $fileContent = $mediaService->read($mediaPath);
        $tempFile = tempnam(sys_get_temp_dir(), 'BAUERGROUPRemoveBackground');
        $tempFileWithExtension = $tempFile.'.'.$mediaType;
        file_put_contents($tempFileWithExtension, $fileContent);

        //API Client
        $client = curl_init();

        curl_setopt($client, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($client, CURLOPT_TIMEOUT, 30);
        curl_setopt($client, CURLOPT_URL, 'https://api.remove.bg/v1.0/removebg');        
        curl_setopt($client, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($client, CURLOPT_HTTPHEADER, array(
            'X-Api-Key: ' . $this->apiKey,
            'User-Agent: BAUERGROUP RemoveBG Client for Shopware 5'
        ));
        curl_setopt($client, CURLOPT_POST, true);
        curl_setopt($client, CURLOPT_SAFE_UPLOAD, true);
        curl_setopt($client, CURLOPT_POSTFIELDS, array(
            'format' => $mediaType,
            'size' => $mediaSize,
            'bg_color' => $mediaBGColor,
            
            'image_file' => curl_file_create(realpath($tempFileWithExtension), 'image/' . $mediaType, $mediaPathInfo['basename'])
        ));

        //Request & Retrive Result
        $imageResult = curl_exec($client);
        
        //Delete Temporary Files
        unlink($tempFile);
        unlink($tempFileWithExtension);
    
        //Check Request Status and Stop processing if there is an error
        $requestInfo = curl_getinfo($client, CURLINFO_HTTP_CODE);        
        if ($requestInfo <> 200)
        {
            Shopware()->Pluginlogger()->error('BAUERGROUPRemoveBackground: Error at using remove.bg Service. Your Image is unmodified. Cause -> Return Code:' . $requestInfo . ' / Return Body: ' . $imageResult);                       
            return;
        }

        //Save Modified Image
        $mediaService->write($mediaPath, $imageResult);

        Shopware()->Pluginlogger()->info('BAUERGROUPRemoveBackground: remove.bg Processed Image ' . $mediaPath . ' with Settings'
        . ' Quality: ' . $mediaSize 
        . ' / Media Type: ' . $mediaType 
        . ' / Background Color: ' . $mediaBGColor);

        /*
        Media Service Useage
        https://developers.shopware.com/developers-guide/shopware-5-media-service/
        https://developers.shopware.com/developers-guide/media-optimizer/#example:-create-optimizer-using-a-http-api

        $mediaService = $container->get('shopware_media.media_service');
        echo $mediaService->getUrl('media/image/my-fancy-image.png');
        $fileExists = $mediaService->has('media/image/my-fancy-image.png');
        $fileContent = $mediaService->read('media/image/my-fancy-image.png');
        $fileStream = $mediaService->readStream('media/image/my-fancy-image.png');
        $mediaService->write('media/image/my-fancy-image.png', $fileContent);
        $mediaService->writeStream('media/image/my-fancy-image.png', $fileStream);
        $mediaService->delete('media/image/my-fancy-image.png');
        $mediaService->rename('media/image/my-fancy-image.png', 'media/image/super-duper-fancy-image.png');
        $mediaService->normalize('https://www.myshop.com/shop/media/image/my-fancy-image.png');
        $mediaService->normalize('/var/www/shop1/media/image/my-fancy-image.png');
        $mediaService->normalize('media/image/5c/af/3e/my-fancy-image.png');
        $url = $mediaService->getUrl('media/image/my-fancy-image.png');
        $url = $mediaService->getUrl('/var/www/shop1/media/image/my-fancy-image.png');
        $url = $mediaService->getUrl($mediaService->normalize('/var/www/shop1/media/image/my-fancy-image.png'));
        */

        /*
        $images = glob('*.jpg');

        foreach($images as $image) 
        {   
            try
            {   
                $img = new Imagick($image);
                $img->stripImage();
                $img->writeImage($image);
                $img->clear();
                $img->destroy();

                echo "Removed EXIF data from $image. \n";

            } catch(Exception $e) {
                echo 'Exception caught: ',  $e->getMessage(), "\n";
            }   
        }
        */
    }
}

?>
