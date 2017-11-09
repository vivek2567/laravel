<?php

namespace App;

use App\AppModel;
use Config;

class Attachment extends AppModel
{

    use Traits\ActsAsImageTrait;

    const MIMETYPES_IMAGE = [
        'image/gif', 'image/jpeg', 'image/png', 'image/bmp'
    ];

    const MIMETYPES_AUDIO = [
        'audio/mpeg3', 'audio/x-mpeg-3', 'audio/mpeg'
    ];

    protected $fillable = ['name', 'internalName', 'mimetype', 'object_type', 'object_id', 'displayorder', 'fileHash', 'fileSize', 'fileName', 'fileExtension', 'customer_id', 'user_id'];

    protected $hidden = [
        'deleted_at', 'object_type', 'object_id', 'fileName', 'internalName'
    ];

    protected $validationRules = [
        "name" => [
            "required" => "attachment.name.required",
        ],

        "object_type" => [
            "required" => "attachment.object_type.required",
        ],
        "object_id" => [
            "required" => "attachment.object_id.required",
        ],
        "fileHash" => [
            "required" => "attachment.fileHash.required",
        ],
        "fileName" => [
            "required" => "attachment.fileName.required",
        ],
        "fileExtension" => [
            "required" => "attachment.fileExtension.required",
        ],
    ];

    public static function boot()
    {
        parent::boot();

        // this is the save beforeFilter
        static::saved(function ($model) {
            if ($model->customer) {
                $model->customer->redeemed_storage = $model->customer->redeemed_storage + $model->fileSize;
                $model->customer->save();
            }

        });
    }

    public function createStandardImages($type = null)
    {
        $cleanedThumbOptions = ["width" => 378];
        $thumbKeyName = $this->__image_generateThumbKeyName($cleanedThumbOptions);
        if(is_null($type)){
            $this->__image_generateThumb($cleanedThumbOptions, $thumbKeyName);
        }else{
            $this->__image_generateThumb($cleanedThumbOptions, $thumbKeyName, $type);
        }

    }

    public function scopeVisibleTo($query, $userObject)
    {
        $userId = Config::get('user_id');
        $customerId = Config::get('customer_id');
        if(!$userId) {
            $userId =1;
        }
        if(!$customerId) {
            $customerId = 1;
        }

        if ($this->hasAttribute('user_id') && $this->hasAttribute('customer_id')) {
            $query->whereRaw("(user_id = " . $userId . " OR customer_id = " . $customerId . " OR object_type = 'userdefaultimage' OR object_type = 'industry')");
        }
        return $query;
    }


    public function getLocation($getAbsoluteLocation = true)
    {
        // cleanup the App\<MODEL> namespace
        $fileLocation = strtolower(str_replace(['App\\', '\\'], ['', '_'], $this->object_type)) . "/" . directoryLogicSeparator($this->object_id) . "/" . $this->object_id;

        if ($getAbsoluteLocation) {
            // get source path from configs
            $attachmentBaseLocation = config('app.api.attachmentBaseLocation');
            return $attachmentBaseLocation . "/" . $fileLocation;
        }
        return $fileLocation;
    }

    public function getFilePath($getAbsoluteLocation = true, $validateFile = true)
    {
        $filePath = $this->getLocation($getAbsoluteLocation) . '/' . $this->fileName . '.' . $this->fileExtension;

        if ($validateFile && $getAbsoluteLocation) {
            if (is_file($filePath) && is_readable($filePath)) {
                return $filePath;
            }
            return false;
        }
        return $filePath;
    }

    public function getAttachmentUrl($thumbOptions = [])
    {
        $cleanedThumbOptions = $this->__image_cleanThumbOptions($thumbOptions);
        return config('app.url') . "/api/attachments/" . $this->getHashFor($this->id) . ((sizeof($cleanedThumbOptions) > 0) ? '?' . http_build_query($cleanedThumbOptions) : "");
    }

    public function customer()
    {
        return $this->belongsTo('App\Customer');
    }

    public function object()
    {
        return $this->morphTo();
    }
}
