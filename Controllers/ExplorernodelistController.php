<?php

namespace App\Http\Controllers;
use App\Explorernode;
use App\ExplorernodePermission;
use App\Product2Folder;
use App\User;
use App\Asset;
use App\Attachment;
use App\Folder;
use App\ManageCustomer;
use App\Object2Object;
use App\Product;
use App\Project;
use App\Services\SeedToElastic;
use Illuminate\Http\Request;
use App\Http\Requests;
use App\Accessright;
use App\Explorernodelist;
use App\Exceptions\APIException;
use App\Providers\RequestStorageProvider;
use App\Http\Helpers\PaginationHelper;
use App\Http\Helpers\FilterHelper;
use App\AppModel;
use App\Http\Helpers\HashHelper;
use App\Events\RemoveProductEvent;
use Event;
use App\Events\AssetDeletedEvent;

use App\CustomerProfileAttribute;
use App\CustomerAdditionalFieldsValue;
use App\CustomerProfile;
class ExplorernodelistController extends AppController
{
    const INTERNAL_NAME_TOKEN = '_';
    const INTERNAL_NAME_PREFIX = ':';

    /**
     * @api {get} /explorernodelist Retrieve list of explorer node list data
     * @apiVersion 0.1.0
     * @apiName GetExplorernodelist
     * @apiGroup Explorernodelist
     * @apiPermission user
     *
     * @apiDescription Retrieve a List of Explorer Node List Data
     *
     * @apiSuccess {Object[]}  explorernodelist Explorernodelist
     * @apiUse ExplorernodelistReturnSuccess
     * @apiUse TimestampsBlock
     */
    public function index(Request $request)
    {
        return PaginationHelper::execPagination(
            'explorernodelist',
            Explorernodelist::where('user_id', $this->currentUser->id),
            $request
        );
    }

    /**
     * @api {post} /explorernodelist Create new explorernodelist
     * @apiVersion 0.1.0
     * @apiName CreateExplorernodelist
     * @apiGroup Explorernodelist
     * @apiPermission user
     *
     * @apiDescription Create new Explorernodelist -
     * Use this endpoint to create a new Explorernodelist
     *
     * @apiParam {String}    name             Explorernodelist Name
     * @apiParam {String}    description      Explorernodelist Description
     * @apiParam {String}    leaf_type        Explorernodelist leaf_type
     * @apiParam {String}    content_type     Explorernodelist Content Type
     * @apiParam {String}    display_order    Explorernodelist Display Order
     * @apiParam {String}    use_in_creator   Explorernodelist Use In Creator
     *
     * @apiSuccess {Object[]}  Explorernodelist            Explorernodelist
     * @apiUse ExplorernodelistReturnSuccess
     *
     * @apiUse TimestampsBlock
     *
     * @apiError (Exceptions) {Exception} ExplorernodelistCreateFailed  Validation of Model failed. See status.returnValues
     */
    public function create(Request $request)
    {
        $explorernodelist = new Explorernodelist;
        $explorernodelist->setSafeAttributes($request->all());
        $explorernodelist->name = $request->name;
        $explorernodelist->description = $request->description;
        $explorernodelist->leaf_type = $request->leaf_type;
        $explorernodelist->content_type = $request->content_type;
        $explorernodelist->leaf_id = $request->leaf_id;
        $explorernodelist->display_order = $request->display_order;
        $explorernodelist->use_in_creator = $request->use_in_creator;
        $explorernodelist->thumbUrl = $request->thumbUrl;
        $explorernodelist->user_id = $this->currentUser->id;
        $explorernodelist->customer_id = $this->currentUser->customer_id;

        if ($explorernodelist->save()) {
            return ['explorernodelist' => $explorernodelist->fresh()];
        } else {
            throw new APIException('EXPLORERNODELIST_CREATE_ERROR', 500, $explorernodelist->getValidationErrorMessages());
        }

    }

    /**
     * @api {delete} /explorernodelist/:id Delete a explorernodelist
     * @apiVersion 0.1.0
     * @apiName Delete Explorernodelist
     * @apiGroup Explorernodelist
     * @apiPermission user
     *
     * @apiDescription Delete a Explorernodelist -
     * Use this endpoint to delete a Explorernodelist
     * @apiSuccess {Object[]}       deleted
     * @apiSuccess {String}         deleted.Explorernodelist_id    Hashed ID of deleted Explorernodelist
     *
     * @apiError (Exceptions) {Exception} EXPLORERNODELIST_NOT_FOUND  Explorernodelist with given ID not found in Explorernodelist
     * @apiError (Exceptions) {Exception} EXPLORERNODELIST_DELETE_ERROR  Unable to delete the Explorernodelist
     */
    public function delete(Request $request)
    {
        $explorernodelist = Explorernodelist::where('id', $request->id)->where('user_id', $this->currentUser->id)->first();
        if ($explorernodelist) {
            Explorernodelist::where('id', $request->id)->delete();
            if ($explorernodelist->delete()) {
                return ['deleted' => true];
            } else {
                throw new APIException('EXPLORERNODELIST_DELETE_ERROR', 500);
            }
        } else {
            throw new APIException('EXPLORERNODELIST_NOT_FOUND', 404);
        }
    }

    /**
     * @api {post} /explorernodelist/deleteAll Delete all explorernodelist
     * @apiVersion 0.1.0
     * @apiName Delete All Explorernodelist
     * @apiGroup Explorernodelist
     * @apiPermission user
     *
     * @apiDescription Delete all Explorernodelist -
     * Use this endpoint to delete all Explorernodelist
     * @apiSuccess {Object[]}       deleted
     * @apiSuccess {String}         deleted.Explorernodelist   Deleted All Explorernodelist
     *
     * @apiError (Exceptions) {Exception} EXPLORERNODELIST_EMPTY_RECORD  Explorernodelist not found in Explorernodelist
     * @apiError (Exceptions) {Exception} EXPLORERNODELIST_DELETE_All_ERROR  Unable to delete all the Explorernodelist
     */
    public function deleteAll()
    {
        $explorernodelistRows = Explorernodelist::where('deleted_at', null)->get()->toArray();
        if ($explorernodelistRows) {
            $explorernodelistRowsDeleted = Explorernodelist::where('deleted_at', null)->delete();
            if ($explorernodelistRowsDeleted) {
                return ['deletedAll' => true];
            } else {
                throw new APIException('EXPLORERNODELIST_DELETE_All_ERROR', 500);
            }
        } else {
            throw new APIException('EXPLORERNODELIST_EMPTY_RECORD', 500);
        }
    }

    /**
     * @api {post} /multipleexplorernodelist Create new explorernodelist
     * @apiVersion 0.1.0
     * @apiName CreateMultipleExplorernodelist
     * @apiGroup Explorernodelist
     * @apiPermission user
     *
     * @apiDescription Create new Explorernodelist -
     * Use this endpoint to create a new Explorernodelist
     *
     * @apiParam {String}    name             Explorernodelist Name
     * @apiParam {String}    description      Explorernodelist Description
     * @apiParam {String}    leaf_type        Explorernodelist leaf_type
     * @apiParam {String}    content_type     Explorernodelist Content Type
     * @apiParam {String}    display_order    Explorernodelist Display Order
     * @apiParam {String}    use_in_creator   Explorernodelist Use In Creator
     *
     * @apiSuccess {Object[]}  Explorernodelist            Explorernodelist
     * @apiUse ExplorernodelistReturnSuccess
     *
     * @apiUse TimestampsBlock
     *
     * @apiError (Exceptions) {Exception} ExplorernodelistCreateFailed  Validation of Model failed. See status.returnValues
     */
    public function multiCreate(Request $request)
    {
        $nodesObj = array();
        $explorernodelist = array();
        $exlist = New Explorernodelist;
        foreach ($request->nodes as $node) {
            $okToInsert= false;
            $nodeId = HashHelper::decodeId($node['id'], config('app.salt'));
            $explorerNode = Explorernode::findNodeById($nodeId, $this->currentUser->id);
            if ($explorerNode->parent_original == 0) {
                //Check if it is a product folder
                $checkProductFolder = Product2Folder::where('folder_id', $explorerNode->id)->first();
                if ($checkProductFolder) {
                    $permissions = ExplorernodePermission::where('permissioned_user_id', $this->currentUser->id)->where('child_id', $checkProductFolder->product_id)->first();
                    if ($permissions) {
                        $permission = json_decode($permissions->permission);
                        if ($permission->share == 1) {
                            $okToInsert = true;
                        } else {
                            $okToInsert = false;
                        }
                    } else {
                        $okToInsert = true;
                    }
                } else {
                    $okToInsert = true;
                }

            } else {
                //Check if parent belongs to same user
                $parentNode = Explorernode::findNodeById($explorerNode->parent_original, $this->currentUser);
                if ($parentNode) {
                    $okToInsert = true;
                } else {
                    $permissions = ExplorernodePermission::where('permissioned_user_id', $this->currentUser->id)->where('leaf_id', $explorerNode->parent_original)->first();
                    if ($permissions) {
                        $permission = json_decode($permissions->permission);
                        if ($permission->share == 1) {
                            $okToInsert = true;
                        } else {
                            $okToInsert = false;
                        }
                    } else {
                        $okToInsert = false;
                    }
                }

            }
            if ($okToInsert) {
                $hashId = HashHelper::decodeId($node['leaf_id'], config('app.salt'));
                $explorernodelist['user_id'] = $this->currentUser->id;
                $explorernodelist['customer_id'] = $this->currentUser->customer_id;
                $explorernodelist['name'] = $node['name'];
                $explorernodelist['description'] = $node['description'];
                $explorernodelist['leaf_type'] = $node['leaf_type'];
                $explorernodelist['content_type'] = $node['content_type'];
                $explorernodelist['leaf_id'] = $hashId;
                $explorernodelist['display_order'] = 0;
                $explorernodelist['use_in_creator'] = $node['use_in_creator'];
                $explorernodelist['thumbUrl'] = $node['thumbUrl'];
                $nodesObj[] = $explorernodelist;
            }
        }

        $data = Explorernodelist::insert($nodesObj);
        if ($data) {
            return ['explorernodelist' => $data];
        } else {
            throw new APIException('EXPLORERNODELIST_CREATE_ERROR', 500, $exlist->getValidationErrorMessages());
        }
    }

    public function createOtherDataFolder(Request $request)
    {
        parent::checkPermissions($request, "manage.otherdata.canAddOtherDataFiles");
        $internalName = ':other_data_manage_root';
        $folderName = $request->folder_name;
        $parentFolder = Folder::where('user_id', $this->currentUser->id)->where("internal_name", $internalName)->first();
        $newFolderInternalName = $this->setInternalNameWithParent($folderName, $parentFolder);

        $newFolder = new Folder();
        $newFolder->name = $folderName;
        $newFolder->user_id = $this->currentUser->id;
        $newFolder->customer_id = $this->currentUser->customer_id;
        $newFolder->internal_name = $newFolderInternalName;

        if (!$newFolder->save()) {
            return false;
        }
        // create relation with parent-folder
        if ($parentFolder) {
            parent::checkPermissions($request, "manage.otherdata.canAddOtherDataFolder");
            $parentFolder->createRelationWith($newFolder);
        }

//        $image = $this->storage_path() . "/placeholder-other-data1.png";
//        $name = 'placeholder-other-data1.png';
        $image = $this->storage_path() . "/folder.png";
        $name = 'folder.png';
        $att = [
            'size' => filesize($image),
            'name' => basename($name),
            'tmp_name' => $image
        ];
        $newFolder->saveAttachment($att, ['customer_id' => $newFolder->customer_id, 'user_id' => $newFolder->user_id]);

        if ($request->type == '') {
            $explorernodelist = Explorernodelist::where('user_id', $this->currentUser->id)->get();
            foreach ($explorernodelist as $nodelist) {
                if ($nodelist['leaf_type'] == 'asset') {
                    $data = array();
                    $data['id'] = $nodelist['leaf_id'];
                    $data['user_id'] = $this->currentUser->id;
                    $data['customer_id'] = $this->currentUser->customer_id;
                    $data['display_in_explorer'] = '1';
                    $assetObject = new Asset();
                    $assetRelation = $assetObject->duplicateAsset($data);
                    if ($assetRelation) {
                        if ($newFolder) {
                            $newFolder->createRelationWith($assetRelation);
                        }
                    }
                }
                /** Code Ends: Create asset*/

                /** Code Starts: Create Project*/
                if ($nodelist['leaf_type'] == 'project') {
                    $data = array();
                    $data['id'] = $nodelist['leaf_id'];
                    $data['user_id'] = $this->currentUser->id;
                    $data['customer_id'] = $this->currentUser->customer_id;
                    $data['display_in_explorer'] = '1';
                    $projectObject = new Project();
                    $projectRelation = $projectObject->duplicateProject($data);
                    if ($projectRelation) {
                        if ($newFolder) {
                            $newFolder->createRelationWith($projectRelation);
                        }
                    }
                }
                /** Code Ends: Create Project*/

                /** Code Starts: Create Customer*/
                if ($nodelist['leaf_type'] == 'managecustomer') {
                    $data = array();
                    $data['id'] = $nodelist['leaf_id'];
                    $data['user_id'] = $this->currentUser->id;
                    $data['customer_id'] = $this->currentUser->customer_id;
                    $data['display_in_explorer'] = '1';
                    $customerObject = new ManageCustomer();
                    $customerRelation = $customerObject->duplicateCustomerForShareToCurrentUser($data);
                    if ($customerRelation) {
                        if ($newFolder) {
                            $newFolder->createRelationWith($customerRelation);
                        }
                    }
                }
                if ($nodelist->leaf_type == 'product') {
                    $productModel = new Product();
                    $product = Product::where('id', $nodelist->leaf_id)->first();
                    $data = array();
                    $data['id'] = $nodelist['leaf_id'];
                    $data['user_id'] = $this->currentUser->id;
                    $data['customer_id'] = $this->currentUser->customer_id;
                    $data['display_in_explorer'] = '1';
                    $productModel->duplicateProduct($product, $newFolder, $data);
                }
            }
        }

        if(config('app.elastic_search'))
        {
            (new SeedToElastic($this->currentUser->id))->seedExplorerNode($newFolder, true);
        }

        return ['otherdata' => $newFolder->fresh()];
    }

    public function setInternalNameWithParent($iName, $parentObject)
    {
        if ($parentObject) {
            $internal_name = self::INTERNAL_NAME_PREFIX . strtolower($iName . self::INTERNAL_NAME_TOKEN . $parentObject->internalNameBreadcrumb);
        } else {
            $internal_name = self::INTERNAL_NAME_PREFIX . strtolower($iName);
        }
        $internal_name = str_replace(" ", "_", $internal_name);
        return $internal_name;
    }


    public function getOtherDataFolders(Request $request)
    {
        $internalName = ':other_data_manage_root';
        $parentFolder = Folder::where('user_id', $this->currentUser->id)->where("internal_name", $internalName)->first();
        $folderArray = array();
        $folders = Object2Object::where("sender_object_id", $parentFolder->id)->where("receiver_object_type", 'Folder')->get();
        foreach ($folders as $folder) {
            $childFolder = Folder::where('user_id', $this->currentUser->id)->where("id", $folder->receiver_object_id)->first();
            if ($childFolder) {
                array_push($folderArray, $childFolder);
            }
        }
        return ['otherdata' => $folderArray];

    }

    /**
     * @param Request $request
     * @return array
     */
    public function saveToExisingOtherDataFolder(Request $request)
    {
        parent::checkPermissions($request, "manage.otherdata.canEditOtherDataFiles");
        $internalName = $request->internal_name;
        $newFolder = Folder::where('user_id', $this->currentUser->id)->where("internal_name", $internalName)->first();
        $explorernodelist = Explorernodelist::where('user_id', $this->currentUser->id)->get();
        foreach ($explorernodelist as $nodelist) {
            if ($nodelist['leaf_type'] == 'asset') {
                $data = array();
                $data['id'] = $nodelist['leaf_id'];
                $data['user_id'] = $this->currentUser->id;
                $data['customer_id'] = $this->currentUser->customer_id;
                $data['display_in_explorer'] = '0';
                $assetObject = new Asset();
                $assetRelation = $assetObject->duplicateAsset($data);
                if ($assetRelation) {
                    if ($newFolder) {
                        $newFolder->createRelationWith($assetRelation);
                        if(config('app.elastic_search')){
                            (new SeedToElastic($this->currentUser))->seedExplorerNode($newFolder);
                        }
                    }
                }
            }
            /** Code Ends: Create asset*/

            /** Code Starts: Create Project*/
            if ($nodelist['leaf_type'] == 'project') {
                $data = array();
                $data['id'] = $nodelist['leaf_id'];
                $data['user_id'] = $this->currentUser->id;
                $data['customer_id'] = $this->currentUser->customer_id;
                $data['display_in_explorer'] = '0';
                $projectObject = new Project();
                $projectRelation = $projectObject->duplicateProject($data);
                if ($projectRelation) {
                    if ($newFolder) {
                        $newFolder->createRelationWith($projectRelation);
                        if(config('app.elastic_search')){
                            (new SeedToElastic($this->currentUser))->seedExplorerNode($newFolder);
                        }
                    }
                }
            }
            /** Code Ends: Create Project*/

            /** Code Starts: Create Customer*/
            if ($nodelist['leaf_type'] == 'managecustomer') {
                $data = array();
                $data['id'] = $nodelist['leaf_id'];
                $data['user_id'] = $this->currentUser->id;
                $data['customer_id'] = $this->currentUser->customer_id;
                $data['display_in_explorer'] = '0';
                $customerObject = new ManageCustomer();
                $customerRelation = $customerObject->duplicateCustomerForShareToCurrentUser($data);
                if ($customerRelation) {
                    if ($newFolder) {
                        $newFolder->createRelationWith($customerRelation);
                        if(config('app.elastic_search')){
                            (new SeedToElastic($this->currentUser->id))->seedExplorerNode($newFolder);
                        }
                    }
                }
            }
            if ($nodelist->leaf_type == 'product') {
                $productModel = new Product();
                $product = Product::where('id', $nodelist->leaf_id)->first();
                $data = array();
                $data['id'] = $nodelist['leaf_id'];
                $data['user_id'] = $this->currentUser->id;
                $data['customer_id'] = $this->currentUser->customer_id;
                $data['display_in_explorer'] = '0';
                $newProduct = $productModel->duplicateProduct($product, $newFolder, $data);
                if ($newProduct) {
                    if(config('app.elastic_search')){
                        (new SeedToElastic($this->currentUser->id))->seedExplorerNode($newProduct, true);
                    }
                }
            }
        }
        return ['otherdata' => $newFolder->fresh()];
    }

    public function storage_path()
    {
        return storage_path() . "/backgrounds";
    }

    /**
     * @api {delete} /folder/:id Delete a folder
     * @apiVersion 0.1.0
     * @apiName Delete folder
     * @apiPermission user
     *
     * @apiDescription Delete a folderId -
     * Use this endpoint to delete a folderId
     * @apiSuccess {Object[]}       deleted
     * @apiSuccess {String}         deleted.folderId    Hashed ID of deleted folderId
     *
     */
    public function deleteFolder(Request $request)
    {
        $canDeleteOtherDataFiles = parent::checkPermissions($request, "manage.otherdata.canDeleteOtherDataFiles");
        $canDeleteAdvertisingProjectFolders = parent::checkPermissions($request, "manage.marketingadvertisingprojects.canDeleteAdvertisingProjectFolders");
        $canDeleteOtherDataFolders = parent::checkPermissions($request, "manage.otherdata.canDeleteOtherDataFolders");
        $canDeleteProductFolders = parent::checkPermissions($request, "manage.products.canDeleteProductFolders");
        if(isset($canDeleteProductFolders) || isset($canDeleteOtherDataFiles) || isset($canDeleteAdvertisingProjectFolders) || isset($canDeleteOtherDataFolders)){
            $explorernodePermission = new ExplorernodePermission;
            $currentfolder = Folder::where('id',$request->id)->first();
            $isParent = false;
            $deleteCheck = false;
            $deleteMultiple = false;
            if ($currentfolder) {
                if ($currentfolder->parent_original == 0) {
                    $isParent = true;
                    $deleteCheck = true;
                    $deleteMultiple = true;
                } else {
                    $permissions = $explorernodePermission->getExplorernodePermissions($currentfolder->parent_original, $this->currentUser);
                    if ($permissions) {
                        $permissions = json_decode($permissions->permission);
                        if ($permissions->delete) {
                            $deleteCheck = true;
                            $deleteMultiple = true;
                        }
                    }
                }
            }
            if ($deleteCheck) {
                if ($deleteMultiple) {
                    if ($isParent) {
                        $explorernodePermission->deleteExplorernodeAccordingToPermissions($currentfolder->id, 'folder', $isParent, $this->currentUser);
                    } else {
                        $explorernodePermission->deleteExplorernodeAccordingToPermissions($currentfolder->parent_original, 'folder', $isParent, $this->currentUser);
                    }
                }
                return ['deleted' => 'Folder Deleted'];
            } else {
                throw new APIException('FOLDER_DELETE_PERMISSION_DENIED', 403);
            }
        }
    }

    public function deleteFolderData($leafId)
    {
        $objectsData = Object2Object::where("sender_object_id", $leafId)->where("sender_object_type", 'Folder')->get();
        foreach ($objectsData as $folder) {
            if ($folder['receiver_object_type'] == 'Product') {
                $product = Product::where('user_id',$this->currentUser->id)->where("id", $folder->receiver_object_id)->first();
                if (!$product) {
                    throw new APIException('PRODUCT_NOT_FOUND', 404);

                } else {

                }
                Event::fire(new RemoveProductEvent($product));
            }
            if ($folder['receiver_object_type'] == 'Asset') {
                $asset = Asset::where('user_id', $this->currentUser->id)->where("id", $folder->receiver_object_id)->first();
                if ($asset) {
                    Event::fire(new AssetDeletedEvent($asset));
                    $asset->delete();
                }
            }
            if ($folder['receiver_object_type'] == 'Project') {
                $project = Project::where('user_id', $this->currentUser->id)->where("id", $folder->receiver_object_id)->first();
                if ($project) {
                    Projectpage::where('project_id', $leafId)->delete();
                    if ($project->delete()) {
                        return ['deleted' => ['project_id' => $leafId]];
                    }
                    throw new APIException('PROJECT_DELETE_ERROR', 500);
                }
                throw new APIException('PROJECT_NOT_FOUND', 404);
            }
            if ($folder['receiver_object_type'] == 'Folder') {
                $this->deleteFolderData($folder->receiver_object_id);
                $innerfolder = Folder::where('id',$folder->receiver_object_id)->first();
                if ($innerfolder) {
                    $innerfolder->delete();
                }
            }

        $folder->delete();
        }
        return true;
    }

    /**
     * @api {delete} /folder/rename/:id Rename a folder
     * @apiVersion 0.1.0
     * @apiName rename folder
     * @apiPermission user
     *
     * @apiDescription Rename a folder -
     * Use this endpoint to rename a folder
     * @apiSuccess {Object[]}       folder
     *
     */
    public function renameFolder(Request $request)
    {
        $currentFolderObject = Folder::where('id', $request->id)->where('user_id', $this->currentUser->id)->first();
        if ($currentFolderObject) {
            $currentFolderObject->name = $request->name;
            $currentFolderObject->description = $request->description;
            $currentFolderObject->save();
            if(config('app.elastic_search'))
            {
                (new SeedToElastic($this->currentUser->id))->seedExplorerNode($currentFolderObject);
            }
            Notification::notifyCurrentUserFriends($this->currentUser->id, $this->currentUser->customer_id_hash,'Folder renamed');
            return ['folder' => $currentFolderObject->fresh()];
        } else {
            throw new APIException('FOLDER_NOT_FOUND', 403);
        }

    }

    public function downloadFileManager(Request $request)
    {
        $customers_download = storage_path() . '/filemanager_download';
        $public_customer_download = public_path() . '/filemanager_download';

        if (!file_exists($customers_download)) {
            mkdir($customers_download, 0777, true);
        }

        if (!file_exists($public_customer_download)) {
            mkdir($public_customer_download, 0777, true);
        }
        $exploredNodeList = Explorernodelist::where('user_id', $this->currentUser->id)->get();
        $nowDate = date('Y-m-d-H-i-s');

        $cnd = [];
        foreach ($exploredNodeList as $explorerNode)
        {
            if ($explorerNode->leaf_type == 'product') {
                $cnd[] = self::downloadProduct($explorerNode->leaf_id, $nowDate);
            } else if ($explorerNode->leaf_type == 'project') {
                //echo $explorerNode->leaf_type;
            } else if ($explorerNode->leaf_type == 'asset') {
                $cnd[] = self::downloadAssets($explorerNode->leaf_id, $nowDate);
            } else if ($explorerNode->leaf_type == 'folder') {
                //echo $explorerNode->leaf_type;
            } else if ($explorerNode->leaf_type == 'managecustomer') {
                $cnd[] = self::downloadCustomer($explorerNode->leaf_id, $nowDate);
            }

        }

        $downloadsStoragePath1 = storage_path('filemanager_download');

        $downloadsPath1 = public_path() . '/filemanager_download';

        $zipName = "{$nowDate}";

        $zipPath = "$downloadsPath1/$zipName";

        //print_r($cnd);
        $command = "cd {$downloadsStoragePath1}; zip -r '{$zipPath}' '{$nowDate}/'";

        exec($command, $output);
        $headers = ['Content-Type' => 'Content-Type: application/zip'];

        $path = config('app.url') . '/filemanager_download/' . $zipName.".zip";

        return ['attachmentURL' => $path];

    }

    public function downloadAssets($id, $nowDate)
    {
        $attachmentObject = Attachment::where('object_id', $id)->where('object_type', 'asset')->first();
        if ($attachmentObject) {
            $assetPath = $this->fetchAssetPath($attachmentObject->object_id);
            if ($this->createProductFolder($attachmentObject, $nowDate)) {
                $productDirectoryName = $this->productDirectoryName($attachmentObject);
                $productDirectory = $this->fetchProductDirectory($attachmentObject, $nowDate);

                copy($assetPath, "$productDirectory/{$attachmentObject['name']}");

                $now = date('Y-m-d-H-i-s');

                $downloadsStoragePath = storage_path('filemanager_download/').$nowDate;

                $downloadsPath = public_path() . '/filemanager_download/'.$nowDate;

                $zipName = "{$attachmentObject->name}-{$now}.zip";

                $zipPath = "$downloadsPath";

                return ['path'=>$downloadsStoragePath, 'zipPath'=> $zipPath, 'dc'=>$productDirectoryName];
            }
        } else {
            throw new APIException('ATTACHMENT_NOT_FOUND', 404);
        }
    }

    public function downloadCustomer($id, $nowDate)
    {
        $userId = $this->currentUser->id;
        $customerId = $this->currentUser->customer_id;
        $manageCustomer = ManageCustomer::where("id", $id)->first();

        if($manageCustomer) {
            $customerprofile = CustomerProfile::where('user_id', $userId)->with('customeradditionalfields')->where("id", $manageCustomer->customer_profile_id)->first();
            $companyaddress = [];
            $contactperson = [];
            $additionaladdress = [];
            if (isset($customerprofile->customeradditionalfields)) {
                foreach ($customerprofile->customeradditionalfields as $customeradditionalfield) {
                    if ($customeradditionalfield->additional_field_category == 'companyaddress') {
                        $attribute = CustomerProfileAttribute::where('id', $customeradditionalfield->customer_profile_attribute_id)->first();
                        $value = CustomerAdditionalFieldsValue::where('manage_customer_id', $id)->where('customer_additional_fields_id', $customeradditionalfield->id)->first();
                        if (!empty($value)) {
                            $attribute->value = $value->value;
                        } else {
                            $attribute->value = '';
                        }
                        $attribute->edit = false;
                        $attribute->parent_id = $customeradditionalfield->id;
                        array_push($companyaddress, $attribute);
                    }

                    if ($customeradditionalfield->additional_field_category == 'contactperson') {
                        $attribute = CustomerProfileAttribute::where('id', $customeradditionalfield->customer_profile_attribute_id)->first();
                        $value = CustomerAdditionalFieldsValue::where('manage_customer_id', $id)->where('customer_additional_fields_id', $customeradditionalfield->id)->first();
                        if (!empty($value)) {
                            $attribute->value = $value->value;
                        } else {
                            $attribute->value = '';
                        }
                        $attribute->edit = false;
                        $attribute->parent_id = $customeradditionalfield->id;
                        array_push($contactperson, $attribute);
                    }

                    if ($customeradditionalfield->additional_field_category == 'additionaladdress') {
                        $attribute = CustomerProfileAttribute::where('id', $customeradditionalfield->customer_profile_attribute_id)->first();
                        $value = CustomerAdditionalFieldsValue::where('customer_additional_address_id', $id)->where('manage_customer_id', $manageCustomer->id)->where('customer_additional_fields_id', $customeradditionalfield->id)->first();
                        if (!empty($value)) {
                            $attribute->value = $value->value;
                        } else {
                            $attribute->value = '';
                        }
                        $attribute->edit = false;
                        array_push($additionaladdress, $attribute);
                    }
                }
            }

            $T_aaray = array_merge($additionaladdress,$companyaddress);
            $final_aaray = array_merge($T_aaray,$contactperson);

            $customers_download = storage_path() . '/filemanager_download';
            $public_customer_download = public_path() . '/filemanager_download';

            if (!file_exists($customers_download)) {
                mkdir($customers_download, 0777, true);
            }

            if (!file_exists($public_customer_download)) {
                mkdir($public_customer_download, 0777, true);
            }

            $customerDirectoryName = $this->customerDirectoryName($manageCustomer, $nowDate);

            $customerDirectory = $this->fetchCustomerDirectory($manageCustomer, $nowDate);

            $list = [['Field Name', 'Value']];

            foreach ($final_aaray as $manageCustomerData) {
                $list[] = [$manageCustomerData["name"], $manageCustomerData["value"]];
            }
            $now = date('Y-m-d-H-i-s');

            $downloadsStoragePath = storage_path('filemanager_download/').$nowDate;

            $downloadsPath = public_path() . '/filemanager_download/'.$nowDate;

            $zipName = "{$manageCustomer->company}-{$now}.zip";

            $zipPath = "$downloadsPath";

            $masterDataPath = "{$customerDirectory}/Customer Data";

            if (!file_exists($masterDataPath)) {
                if (mkdir($masterDataPath, 0755, true)) {
                    $fp = fopen("{$masterDataPath}/CustomerData.csv", 'w');

                    foreach ($list as $fields) {
                        fputcsv($fp, $fields);
                    }

                    fclose($fp);
                }
            }



            return ['path'=>$downloadsStoragePath, 'zipPath'=> $zipPath, 'dc'=>$customerDirectoryName];

        } else {
            throw new APIException('OBJECT_NOT_FOUND', 404);
        }

    }

    /**
     * @param $customer
     * @return string
     * @throws APIException
     */

    protected function customerDirectoryName($customer, $nowDate)
    {
        $now = date('Y-m-d-H-i-s');

        return strtolower(trim("{$customer->id}-{$now}"));
    }
    /**
     * @param $customer
     * @return string
     * @throws APIException
     */

    protected function fetchCustomerDirectory($customer, $nowDate)
    {
        $dirName = $this->customerDirectoryName($customer, $nowDate);

        $dirPath = storage_path("filemanager_download/{$nowDate}/{$dirName}");

        return $dirPath;
    }

    public function downloadProduct($id, $nowDate)
    {
        $productObj = Product::where('user_id', $this->currentUser->id)->with('productassets')->where("id", $id)->first();
        if ($productObj) {

            $products_download = storage_path() . '/filemanager_download';

            $public_download = public_path() . '/filemanager_download';

            if (!file_exists($products_download)) {

                mkdir($products_download, 0777, true);
            }

            if (!file_exists($public_download)) {

                mkdir($public_download, 0777, true);
            }

            $product = $productObj->getProduct();

            if ($this->createProductFolder($product, $nowDate)) {

                $productDirectoryName = $this->productDirectoryName($product);

                $productDirectory = $this->fetchProductDirectory($product, $nowDate);

                $folderPaths = [];

                foreach ($product->folders as $folder) {

                    $folderPath = "$productDirectory/{$folder['folder_name']}";

                    if (!file_exists($folderPath)) {

                        if (mkdir($folderPath, 0777, true)) {

                            $assetPaths = [];

                            foreach ($folder['assets'] as $asset) {

                                $attachmentPath = $this->fetchAssetPath($asset['id']);

                                if ($attachmentPath && file_exists($attachmentPath)) {

                                    copy($attachmentPath, "$folderPath/{$asset['name']}");
                                }

                                $assetPaths[] = $attachmentPath;
                            }

                            $folderPaths[$folderPath][] = ['folderPath' => $folderPath, 'assetPaths' => $assetPaths];

                        } else {
                            throw new APIException('COULD_NOT_CREATE_PRODUCT_FOLDER', 500);
                        }
                    }
                }

                $list = [['Name', 'Value', 'Category']];

                foreach ($product->properties as $productProperty) {

                    $list[] = [$productProperty["name"], $productProperty["value"], $productProperty["category"]];
                }

                $now = date('Y-m-d-H-i-s');

                $downloadsStoragePath = storage_path('filemanager_download/').$nowDate;

                $downloadsPath = public_path() . '/filemanager_download/'.$nowDate;

                $zipName = "{$product->name}-{$now}.zip";

                $zipPath = "$downloadsPath";

                $masterDataPath = "{$productDirectory}/Master Data";

                if (mkdir($masterDataPath, 0755, true)) {

                    $fp = fopen("{$masterDataPath}/MasterData.csv", 'w');

                    foreach ($list as $fields) {
                        fputcsv($fp, $fields);
                    }

                    fclose($fp);
                }

                return ['path'=>$downloadsStoragePath, 'zipPath'=> $zipPath, 'dc'=>$productDirectoryName];

            }
        }
        throw new APIException('PRODUCT_NOT_FOUND', 404);
    }

    protected function createProductFolder($product, $nowDate)
    {
        $productDirectory = $this->fetchProductDirectory($product, $nowDate);

        try {

            if (file_exists($productDirectory) && is_dir($productDirectory)) {

                if (rmdir($productDirectory)) {

                    return mkdir($productDirectory, 0775, true);
                }

                return false;
            }

            if(mkdir($productDirectory, 0775, true)){
                return true;
            }

            throw new APIException('UNCABLE_TO_CRETAE_PRODUCT_FOLDER', 500);

        } catch (\Exception $e) {

            throw new APIException('UNCABLE_TO_CRETAE_PRODUCT_FOLDER', 500);
        }
    }

    /**
     * @param $product
     * @return string
     * @throws APIException
     */

    protected function fetchProductDirectory($product, $nowDate)
    {
        $dirName = $this->productDirectoryName($product);

        $dirPath = storage_path("filemanager_download/{$nowDate}/{$dirName}");

        return $dirPath;
    }

    /**
     * @param $product
     * @return string
     * @throws APIException
     */

    protected function productDirectoryName($product)
    {
        $now = date('Y-m-d-H-i-s');

        return strtolower(trim("{$product->name}-{$now}"));
    }

    /**
     * @param $product
     * @return string|false
     * @throws APIException
     */

    private function fetchAssetPath($assetId)
    {
        // FIRST CHECK IN USER / CUSTOMER SECTION
        $attachmentObject = Attachment::where('object_id', $assetId)->first();

        if ($attachmentObject) {

            return $attachmentObject->getFilePath();
        }

        return false;
    }

}