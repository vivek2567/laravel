<?php
/**
 *
 */
namespace App\Http\Controllers;
use App\Asset;
use App\Events\AssetDeletedEvent;
use App\Events\RemoveProductEvent;
use App\Explorernode;
use App\ExplorernodePermission;
use App\ExplorernodePermissionsHistory;
use App\Folder;
use App\Jobs\FolderDuplicateJob;
use App\Jobs\ProductDuplicateJob;
use App\Jobs\ProjectDuplicate;
use App\ManageCustomer;
use App\Notification;
use App\Product;
use App\Product2Folder;
use App\Project;
use App\Services\SeedToElastic;
use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Helpers\HashHelper;
use App\Exceptions\APIException;
use App\Attachment;
use App\User;
use Validator;
use App\Http\Helpers\PaginationHelper;
use Log;

class ExplorernodePermissionsController extends AppController
{
    /**
     * @api {post} /explorernodepermission Create a new permission for a node
     * @apiVersion 0.1.0
     * @apiName CreateExplorernodePermission
     * @apiGroup ExplorernodePermission
     * @apiPermission user
     *
     * @apiDescription Create a new permission for a node -
     * Use this endpoint to create a new permission for a node.
     *
     * @apiParam {String}    rights        User Rights
     * @apiParam {String}    node              node to set permissions for
     * @apiUse ExplorernodePermissionReturnSuccess
     *
     * @apiUse TimestampsBlock
     *
     * @apiError (Exceptions) {Exception} EXPLORERNODE_PERMISSION_CREATE_ERROR  Validation of Model failed. See status.returnValues
     *
     * @apiError (Exceptions) {Exception} EXPLORERNODE_PERMISSION_CREATE_ERROR  Validation of Model failed. See status.returnValues
     */

    public function create(Request $request)
    {
        $userId = $this->currentUser->id;
        $customerId = $this->currentUser->customer_id;
        $parentID = HashHelper::decodeId($request['node']['id'], config('app.salt'));
        $leafId = HashHelper::decodeId($request['node']['leaf_id'], config('app.salt'));
        $leafType = $request['node']['leaf_type'];
        $nodeStatus = $request['status'];
        $explorernodePermissions = array();
        $currentUserNodeUpdated = false;

        //update node for the current user first
        if ($leafType === 'asset') {
            $currentUserAsset = Asset::where('user_id', $userId)->where('id',$leafId)->first();
            $currentUserAsset->nodestatus = $nodeStatus;
            $currentUserAsset->use_in_creator = $request['use_in_creator'];
            if ($currentUserAsset->save())  {
                if(config('app.elastic_search'))
                {
                    $seedToElastic = new SeedToElastic($userId);
                    $seedToElastic->seedAsset($currentUserAsset->id);
                    (new SeedToElastic($userId))->seedExplorerNode($currentUserAsset, true);
                }
                $currentUserNodeUpdated = $currentUserAsset;
            }
        } else if ($leafType === 'product') {
            $currentUserProduct = Product::where('user_id', $userId)->where('id',$leafId)->first();
            $currentUserProduct->nodestatus = $nodeStatus;
            $currentUserProduct->use_in_creator = $request['use_in_creator'];
            if ($currentUserProduct->save())  {
                $currentUserProduct->updateProductChildNodestatus($nodeStatus);
                if(config('app.elastic_search'))
                {
                    (new SeedToElastic($userId))->seedExplorerNode($currentUserProduct->fresh(), true);
                }
                $currentUserNodeUpdated = $currentUserProduct;
            }
        } else if ($leafType == 'project') {
            $currentUserProject = Project::where('user_id', $userId)->where('id',$leafId)->first();
            $currentUserProject->nodestatus = $nodeStatus;
            if ($currentUserProject->save())  {
                if(config('app.elastic_search'))
                {
                    (new SeedToElastic($userId))->seedExplorerNode($currentUserProject, true);
                }
                $currentUserNodeUpdated = $currentUserProject;
            }
        } else if ($leafType == 'managecustomer') {
            $currentUserCustomer = ManageCustomer::where('user_id', $userId)->where('id',$leafId)->first();
            $currentUserCustomer->nodestatus = $nodeStatus;
            if ($currentUserCustomer->save())  {
                if(config('app.elastic_search'))
                {
                    (new SeedToElastic($userId))->seedExplorerNode($currentUserCustomer, true);
                }
                $currentUserNodeUpdated = $currentUserCustomer;
            }

        } else if ($leafType == 'folder') {
            $currentUserFolder = Folder::where('user_id', $userId)->where('id',$leafId)->first();
            $currentUserFolder->nodestatus = $nodeStatus;
            if ($currentUserFolder->save())  {
                $currentUserFolder->updateFolderChildNodestatus($currentUserFolder, $nodeStatus);
                if(config('app.elastic_search'))
                {
                    (new SeedToElastic($userId))->seedExplorerNode($currentUserFolder, true);
                }
                $currentUserNodeUpdated = $currentUserFolder;
            }
        }

        /**
         * update or insert permissions
         */
        $permissionsArray = array();
        foreach ($request->rights as $right) {
            $permissionedUserId = HashHelper::decodeId($right['user_id'], config('app.salt'));
            $existingPermission = ExplorernodePermission::where('leaf_type', $leafType)->where('leaf_id', $leafId)->where('permissioned_user_id', $permissionedUserId)->first();
            if (!$existingPermission) {

                $permissions = $right;
                $explorernodePermission = new ExplorerNodePermission;
                $explorernodePermission->leaf_id = $leafId;
                $explorernodePermission->leaf_type = $leafType;
                $explorernodePermission->permissioned_user_id =  $permissionedUserId;
                $explorernodePermission->permission =  json_encode($permissions);
                $explorernodePermission->user_id = $userId;
                $explorernodePermission->customer_id = $customerId;
                $explorernodePermission->save();
                if ($leafType === 'asset') {
                    $data = array();

                    $data['id'] = $leafId;
                    $data['user_id'] = $permissionedUserId;
                    $data['customer_id'] = $customerId;
                    $data['display_in_explorer'] = '1';
                    $data['use_in_creator'] = $request['use_in_creator'];
                    $data['nodestatus'] = $nodeStatus;
                    $assetObject = new Asset();
                    $assetRelation = $assetObject->duplicateAsset($data);
                    if ($assetRelation) {
                        $explorernodePermission->child_id =  $assetRelation->id;
                        $explorernodePermission->save();
                        $asset = Explorernode::findById($parentID);
                        $parentObject = $asset->parent();
                        if ($parentObject) {
                            $parentFolder= Folder::where('internal_name', $parentObject->internal_name)->where('user_id', $permissionedUserId)->first();
                            if ($parentFolder) {
                                $parentFolder->createRelationWith($assetRelation);
                                if(config('app.elastic_search'))
                                {
                                    $seedToElastic = new SeedToElastic($permissionedUserId);
                                    $seedToElastic->seedAsset($assetRelation->id);
                                    (new SeedToElastic($permissionedUserId))->seedExplorerNode($assetRelation, true);
                                }
                            }
                        }
                    }

                } else if ($leafType === 'product') {
                    $data = array();
                    //$userDatas = User::where('id', $permissionedUserId)->first();
                    $data['id'] = $leafId;
                    $data['user_id'] = $permissionedUserId;
                    $data['customer_id'] = $customerId;
                    $data['display_in_explorer'] = '1';
                    $data['use_in_creator'] = $request['use_in_creator'];
                    $data['nodestatus'] = $nodeStatus;


                    $folder = Folder::where('user_id', $permissionedUserId)->where('internal_name', ':products_manage_root')->first();
                    //dispatch(new ProductDuplicateJob($userId, $leafId, $folder->id, $data, true, $explorernodePermission->id, $permissionedUserId));
                    $productDuplicateJob = new ProductDuplicateJob($userId, $leafId, $folder->id, $data, true,$explorernodePermission->id, $permissionedUserId);
                    dispatch($productDuplicateJob);

                } else if ($leafType == 'project') {
                    $data = array();

                    $data['id'] = $leafId;
                    $data['user_id'] = $permissionedUserId;
                    $data['customer_id'] = $customerId;
                    $data['display_in_explorer'] = '0';
                    $data['nodestatus'] = $request['status'];
                    $parentFolder = Folder::where('internal_name', ':advertising_projects_marketing_manage_root')
                                        ->where('user_id', $permissionedUserId)
                                        ->first();

                    if(!is_null($parentFolder))
                    {
                        dispatch(new ProjectDuplicate($parentFolder->id, $data, $explorernodePermission->id));
                    }

                } else if ($leafType == 'managecustomer') {
                    $data = array();

                    $data['id'] = $leafId;
                    $data['user_id'] = $permissionedUserId;
                    $data['customer_id'] = $customerId;
                    $data['display_in_explorer'] = '0';
                    $data['nodestatus'] = $request['status'];
                    $customerObject = new ManageCustomer();
                    $customerRelation = $customerObject->duplicateCustomerForShare($data);
                    if ($customerRelation) {
                        $explorernodePermission->child_id =  $customerRelation->id;
                        $explorernodePermission->save();
                        $parentFolder = Folder::where('internal_name', ':customers_manage_root')->where('user_id', $permissionedUserId)->first();
                        if ($parentFolder) {
                            $parentFolder->createRelationWith($customerRelation);
                            if(config('app.elastic_search'))
                            {
                                (new SeedToElastic($permissionedUserId))->seedExplorerNode($customerRelation, true);
                            }
                        }
                    }
                } else if ($leafType == 'folder') {

                    $data = array();
                    $data['id'] = $leafId;
                    $data['user_id'] = $permissionedUserId;
                    $data['customer_id'] = $customerId;
                    $data['display_in_explorer'] = '0';
                    $data['nodestatus'] = $request['status'];

                    $productExplorerNode = Explorernode::findById($parentID);
                    $parentObject = $productExplorerNode->parent();
                    $parentFolder = Folder::where('internal_name', $parentObject->internal_name)
                                        ->where('user_id', $permissionedUserId)
                                        ->first();


                    dispatch(new FolderDuplicateJob($leafId, $parentFolder->id, $data, $permissionedUserId, $explorernodePermission->id));
                }
                array_push($explorernodePermissions, $explorernodePermission);
            } else {
                $permissions = $right;
                $existingPermission->permission =  json_encode($permissions);
                $existingPermission->save();
                array_push($explorernodePermissions, $existingPermission);

                if ($leafType === 'asset') {

                    $permissoinedAsset = Asset::where('user_id', $existingPermission->permissioned_user_id)->where('id',$existingPermission->child_id)->first();
                    $permissoinedAsset->nodestatus = $nodeStatus;
                    $permissoinedAsset->use_in_creator = $request['use_in_creator'];
                    if ($permissoinedAsset->save())  {
                        if(config('app.elastic_search'))
                        {
                            $seedToElastic = new SeedToElastic($permissionedUserId);
                            $seedToElastic->seedAsset($permissoinedAsset->id);
                            (new SeedToElastic($permissionedUserId))->seedExplorerNode($permissoinedAsset, true);
                        }
                    }
                } else if ($leafType === 'product') {

                    $permissionedProduct = Product::where('user_id', $existingPermission->permissioned_user_id)->where('id',$existingPermission->child_id)->first();
                    if ($permissionedProduct) {
                        $permissionedProduct->nodestatus = $nodeStatus;
                        $permissionedProduct->use_in_creator = $request['use_in_creator'];
                        if ($permissionedProduct->save())  {
                            $permissionedProduct->updateProductChildNodestatus($nodeStatus);
                            if(config('app.elastic_search'))
                            {
                                (new SeedToElastic($permissionedUserId))->seedExplorerNode($permissionedProduct->fresh(), true);
                            }
                        }
                    }
                } else if ($leafType == 'project') {

                    $permissionedProject = Project::where('user_id', $existingPermission->permissioned_user_id)->where('id',$existingPermission->child_id)->first();
                    $permissionedProject->nodestatus = $nodeStatus;
                    if ($permissionedProject->save())  {
                        if(config('app.elastic_search'))
                        {
                            (new SeedToElastic($permissionedUserId))->seedExplorerNode($permissionedProject, true);
                        }
                    }
                } else if ($leafType == 'managecustomer') {

                    $permissionedCustomer = ManageCustomer::where('user_id', $existingPermission->permissioned_user_id)->where('id',$existingPermission->child_id)->first();
                    if ($permissionedCustomer) {
                        $permissionedCustomer->nodestatus = $nodeStatus;
                        if ($permissionedCustomer->save()) {
                            if (config('app.elastic_search')) {
                                (new SeedToElastic($permissionedUserId))->seedExplorerNode($permissionedCustomer, true);
                            }
                        }
                    }

                } else if ($leafType == 'folder') {

                    $permissionedFolder = Folder::where('user_id', $existingPermission->permissioned_user_id)->where('id',$existingPermission->child_id)->first();
                    if ($permissionedFolder) {
                        $permissionedFolder->nodestatus = $nodeStatus;
                        if ($permissionedFolder->save())  {
                            $permissionedFolder->updateFolderChildNodestatus($permissionedFolder, $nodeStatus);
                            if(config('app.elastic_search'))
                            {
                                (new SeedToElastic($permissionedUserId))->seedExplorerNode($permissionedFolder, true);
                            }
                        }
                    }
                }
            }
        }

        /**
         * Remove permissions
         */

        foreach ($request->rights as $right) {
            $permissionedUserId = HashHelper::decodeId($right['user_id'], config('app.salt'));
            $existingPermission = ExplorernodePermission::where('leaf_type', $leafType)->where('leaf_id', $leafId)->where('permissioned_user_id', $permissionedUserId)->first();
            if ($existingPermission) {
                array_push($permissionsArray, $existingPermission->id);
            }
        }

        $nodePermissions = ExplorernodePermission::where('leaf_type', $leafType)->where('leaf_id', $leafId)->get();
        foreach ($nodePermissions as $permission) {
            if (!in_array($permission->id, $permissionsArray, true)) {
                //$user = User::where('id', $permission->permissioned_user_id)->first();
                if ($leafType == 'asset') {
                    $asset = Asset::where('id', $permission->child_id)->first();
                    if ($asset) {
                        //\Event::fire(new AssetDeletedEvent($asset));
                        Notification::notifyCurrentUserFriends($permission->permissioned_user_id, $this->currentUser->customer_id_hash, 'Asset deleted');
                        if(config('app.elastic_search')) {
                            $seedToElastic = new SeedToElastic($permission->permissioned_user_id);
                            $seedToElastic->removeExplorerNode($asset);
                            $seedToElastic->removeAsset($asset->id);
                        }
                        //$asset->delete();
                    }
                } else if ($leafType == 'product') {
                    $product = Product::where('id', $permission->child_id)->first();
                    if ($product) {
                        //\Event::fire(new RemoveProductEvent($product));
                        if(config('app.elastic_search'))
                        {
                            (new SeedToElastic($permission->permissioned_user_id))->removeExplorerNode($product);
                        }
                        //$product->delete();
                        Notification::notifyCurrentUserFriends($permission->permissioned_user_id, $this->currentUser->customer_id_hash, 'Product removed');
                    }
                } else if ($leafType == 'managecustomer') {
                    $customer = ManageCustomer::where('id', $permission->child_id)->first();
                    if ($customer) {
                        if(config('app.elastic_search')){
                            (new SeedToElastic($permission->permissioned_user_id))->removeExplorerNode($customer);
                        }
                        $customer->delete();
                    }
                } else if ($leafType == 'folder') {
                    $folder = Folder::where('id', $permission->child_id)->first();
                    if ($folder) {
                        if(config('app.elastic_search'))
                        {
                            (new SeedToElastic($permission->permissioned_user_id))->removeExplorerNode($folder);
                        }
                        $folder->delete();
                    }
                }

                //$permission->delete();

            }
        }
        if ($explorernodePermissions) {
            return ['explorernode_permission' => $explorernodePermissions];
        } else if ($currentUserNodeUpdated) {
            return ['explorernode_permission' => $currentUserNodeUpdated];
        } else {
            throw new APIException('EXPLORERNODE_PERMISSION_CREATE_ERROR', 500, 'Pemrissions could not be created');
        }
    }

    /**
     * @api {get} /explorernodepermission/:id Retrieve permission for a node
     * @apiVersion 0.1.0
     * @apiName ShowExplorernodePermission
     * @apiGroup ExplorernodePermission
     * @apiPermission user
     *
     * @apiDescription Retrieve ExplorernodePermission -
     * Use this endpoint to recieve permission for a node
     *
     *
     * @apiSuccess {Object[]}  explorernode_permission
     * @apiUse ExplorernodePermissionReturnSuccess
     *
     * @apiUse TimestampsBlock
     *
     * @apiError (Exceptions) {Exception} EXPLORERNODEPERMISSIONS_NOT_FOUND  ExplorernodePermission with given ID not found
     */
    public function show(Request $request)
    {
        $userId = $this->currentUser->id;
        $node = Explorernode::findNodeById($request->id, $userId);
        if ($node->parent_original == 0) {
            //Check if it is a product folder
            $checkProductFolder = Product2Folder::where('folder_id', $node->id)->first();
            if ($checkProductFolder) {
                $permissions = ExplorernodePermission::where('permissioned_user_id', $userId)->where('child_id', $checkProductFolder->product_id)->first();
                if ($permissions) {
                    return ['explorernode_permission' => json_decode($permissions->permission)];
                } else {
                    return ['explorernode_permission' => array("is_parent"=> true)];
                }
            } else {
                return ['explorernode_permission' => array("is_parent"=> true)];
            }

        } else {
            //Check if parent belongs to same user
            $parentNode = Explorernode::findNodeById($node->parent_original, $userId);
            if ($parentNode) {
                return ['explorernode_permission' => array("is_parent"=> true)];
            } else {
                $permissions = ExplorernodePermission::where('permissioned_user_id', $userId)->where('leaf_id', $node->parent_original)->first();
                if ($permissions) {
                    return ['explorernode_permission' => json_decode($permissions->permission)];
                } else {
                    return ['explorernode_permission' => array("is_parent"=> true)];
                }
            }

        }
    }

    /**
     * Set explorer node permission
     * @param Request $request
     * @return array
     * @throws APIException
     */
    public function getExploredNodePermissions(Request $request)
    {
        // asset, product, project, managecustomer, folder
        $userId = $this->currentUser->id;
        $classObject = '';
        $node = $request->node;
        $existingPermission = ExplorernodePermission::where('leaf_type', $request->leaf_type )->where('leaf_id', $request->leaf_id)->get();
        if ($request->leaf_type == 'asset') {
            $classObject = Asset::where('user_id', $userId)->where('id', $request->leaf_id)->first();
            $node['use_in_creator'] = $classObject->use_in_creator;
        }
        elseif ($request->leaf_type == 'product') {
            $classObject = Product::where('user_id', $userId)->where('id', $request->leaf_id)->first();
            $node['use_in_creator'] = $classObject->use_in_creator;
        }
        elseif ($request->leaf_type == 'project') {
            $classObject = Project::where('user_id', $userId)->where('id', $request->leaf_id)->first();
        }
        elseif ($request->leaf_type == 'managecustomer') {
            $classObject = ManageCustomer::where('user_id', $userId)->where('id', $request->leaf_id)->first();
        }
        elseif ($request->leaf_type == 'folder') {
            $classObject = Folder::where('user_id', $userId)->where('id', $request->leaf_id)->first();
        }

        if($classObject) {
            foreach ($existingPermission as $permission) {
                $userPermission = json_decode($permission['permission']);
                $user = $userPermission->user;
                $nuser['firstName'] = $user->firstName;
                $nuser['id'] = $user->id;
                $nuser['lastName'] = $user->lastName;
                $nuser['customerId'] = $user->customerId;
                $nuser['jobTitle'] = $user->jobTitle;
                $permission->user = $nuser;
                $permission->edit = $userPermission->edit;
                $permission->open = $userPermission->open;
                $permission->share = $userPermission->share;
                $permission->download = $userPermission->download;
                $permission->delete = $userPermission->delete;
                $permission->user_id = $userPermission->user_id;
            }
            return ['node' => $node, 'rights' => $existingPermission];
        } else {
            throw new APIException('ASSET_NOT_FOUND', 404);
        }
    }
}