<?php

namespace App\Http\Controllers;
use App\Http\Requests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Exceptions\APIException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use App\Attachment;
use App\Asset;

class AttachmentsController extends AppController
{
    private $userId;
    private $customerId;

    public function __construct(Request $request)
    {
        //$this->middleware("api.attachment.auth");
        $this->middleware("api.decodeHashedFields");
        # don't execute AppController Middleware here
        #parent::__construct($request);
    }


    public function index(Request $request)
    {
        return PaginationHelper::execPagination(
            'attachments',
            Attachment::where('user_id'. $request->currentUser->id),
            $request
        );
    }


    public function create(Request $request)
    {
        $att = [
            'tmp_name' => storage_path() . '/import/imgtest/110.jpg',
            'name' => "Ein wirklich schönes Bild.jpg",
            'size' => 1
        ];

        $res = $request->currentUser->saveAttachment($att, ['customer_id' => $this->currentUser->customer_id, 'user_id' => $this->currentUser->id]);

        dd($res[1]->getValidationErrorMessages());
    }


    /**
     * @api {get} /attachments/:id Get Original Attachment File
     * @apiVersion 0.1.0
     * @apiName GetAttachment
     * @apiGroup Attachment
     * @apiPermission attachmentToken
     *
     * @apiDescription Get an attachment -
     * Use this endpoint to get an attachment
     *
     * @apiParam {String}    token           attachmentToken of User
     *
     * @apiDescription Returns an attachment
     *
     * @apiSuccess {File}  file Imagefile
     */
    public function file(Request $request)
    {
        $attachmentObject = $this->findObject($request);

        $absAttachmentPath = $attachmentObject->getFilePath();
        if ($absAttachmentPath) {
            if ($attachmentObject->object_type == 'asset') {
                $getAssetData = Asset::where('id', $attachmentObject->object_id)->first();
                if ($getAssetData) {
                    $priceCategory = $getAssetData->bo_price_category;
                }

                if (in_array($attachmentObject->mimetype, Attachment::MIMETYPES_IMAGE)) {
                    if (isset($priceCategory) && $priceCategory == '4') {
                        return response()->file($absAttachmentPath,$this->getCORSHeaders());
                    }else {
                        $image = $this->getWatermarkedImage($absAttachmentPath);
                        return response()->file($image,$this->getCORSHeaders());
                    }

                }
            }
            return response()->file($absAttachmentPath,$this->getCORSHeaders());
        }
        throw new NotFoundHttpException();
    }

    /**
     * @api {get} /attachments/:id/thumb Get Thumbnail of Attachment
     * @apiVersion 0.1.0
     * @apiName GetAttachmentThumb
     * @apiGroup Attachment
     * @apiPermission attachmentToken
     *
     * @apiDescription Get a thumb of an attachment -
     * Use this endpoint to get an attachment's thumbnail
     *
     * @apiParam {String}     token           attachmentToken of User
     * @apiParam {Integer}    [width]         width of attchment
     * @apiParam {Integer}    [height]        height of attchment
     * @apiParam {Boolean}    [crop]          Crop the image to fit exactly in the width and height parameters
     * @apiParam {Boolean}    [greyscale]     Make the image in black and white
     * @apiParam {Boolean}    [negative]      Invert the image
     * @apiParam {Float}      [rotate]        Rotate the image
     * @apiParam {Float}      [gamma]         Control the gamma of the image
     * @apiParam {Float}      [blur]          Apply some blur on the image
     * @apiParam {String}     [colorize]      Colorize the image. (Hex color value)
     *
     *
     * @apiSuccess {File}  file Imagefile
     */
    public function thumb(Request $request)
    {
        //get the data to apply the check for the water mark over the images.
        $getAttachmentData = Attachment::where('id', $request->id)->first();
        if (isset($getAttachmentData->object_type) && ($getAttachmentData->object_type == 'asset') ) {
            if ($getAttachmentData) {
                $getAssetData = Asset::where('id', $getAttachmentData->object_id)->first();
                if ($getAssetData) {
                    $priceCategory = $getAssetData->bo_price_category;
                }
            }
        }

        $attachmentObject = $this->findObject($request);

        $absThumbPath = null;
        try {
            $absThumbPath = $attachmentObject->getThumbPath($request->all());
        } catch (Exception $e) {

        } finally {

            if ($absThumbPath == null) {
                $absThumbPath = storage_path() . "/imageerror.png";
            } else {

                if (in_array($attachmentObject->mimetype, Attachment::MIMETYPES_IMAGE)) {

                    if (isset($priceCategory) && $priceCategory == '4') {

                        return response()->file($absThumbPath,$this->getCORSHeaders());
                    } else if ($getAttachmentData->object_type == 'asset') {
                        $image = $this->getWatermarkedImage($absThumbPath);
                        return response()->file($image,$this->getCORSHeaders());
                    }
                }
            }
            return response()->file($absThumbPath,$this->getCORSHeaders());
        }
    }

    ##################
    # PROTECTED AREA #
    ##################


    ################
    # PRIVATE AREA #
    ################

    private function findObject($request)
    {
        // FIRST CHECK IN USER / CUSTOMER SECTION
        $attachmentObject = Attachment::find($request->id);
        if (!$attachmentObject) {
             if (empty($attachmentObject->object) || $attachmentObject->object->public !== true) {
                throw new APIException('OBJECT_NOT_FOUND', 500);
            }
        }

        // toDo: check rights for user <-> customer
        if (false) {
            throw new UnauthorizedHttpException();
        }

        return $attachmentObject;
    }


    private function getWatermarkedImage($path)
    {
        $stamp = imagecreatefrompng(storage_path() . "/TC_Watermark.png");
        if (substr_count($path, ".png") > 0) $im = imagecreatefrompng($path);
        else $im = imagecreatefromjpeg($path);

        $size = getimagesize($path);
        $wartermarkSizes = getimagesize(storage_path() . "/TC_Watermark.png");
        $sx = floor($size[0] * 0.45);
        $sy = floor($sx * (((100 / $wartermarkSizes[0]) * $wartermarkSizes[1])) / 100);

        $wmresized = imagecreatetruecolor($sx, $sy);
        $background = imagecolorallocatealpha($wmresized, 255, 255, 255, 127);
        imagecolortransparent($wmresized, $background);
        imagealphablending($wmresized, false);
        imagesavealpha($wmresized, true);
        imagecopyresized($wmresized, $stamp, 0, 0, 0, 0, $sx, $sy, $wartermarkSizes[0], $wartermarkSizes[1]);


        $dest_x = floor(($size[0] / 2) - ($sx / 2));
        $dest_y = floor(($size[1] / 2) - ($sy / 2));

        // Wasserzeichen auf das Foto kopieren, die Position berechnet sich dabei aus
        // den Rändern und der Bildbreite
        imagecopy($im, $wmresized, $dest_x, $dest_y, 0, 0, $sx, $sy);

        // Ausgeben und aufräumen

        if (!is_dir(storage_path() . "/tmp/")) mkdir(storage_path() . "/tmp/", 0755, true);
        $storepath = storage_path() . "/tmp/watermarked_" . md5($path) . ".jpg";
        imagejpeg($im, $storepath);
        imagedestroy($im);
        return $storepath;
    }

    private function getCORSHeaders(){
        $headers = array();
        $headers['Access-Control-Allow-Origin'] = '*';
        $headers['Access-Control-Allow-Methods'] = 'GET, POST, PUT, DELETE, OPTIONS';
        $headers['Access-Control-Allow-Headers'] = 'accept, content-type, authorization';
        return $headers;
    }

}