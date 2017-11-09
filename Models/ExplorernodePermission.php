<?php

namespace App;

use App\AppModel;
use App\Events\AssetDeletedEvent;
use App\Events\RemoveProductEvent;
use App\Object2Object;
use App\Exceptions\APIException;
use App\Services\SeedToElastic;
use Config;

class ExplorernodePermission extends AppModel
{
    protected $guarded = ['customer_id', 'user_id', 'created_at', 'updated_at', 'deleted_at'];

    protected $hidden = [
        'deleted_at'
    ];

    // safeAttributes list
    protected $safeAttributes = [
        ['leaf_id', 'leaf_type']
    ];

    /*
     * ------------------------ 
     * RELATIONS BLOCK
     * ------------------------
     */

    public function getExplorernodePermissions($leafId, $user = null)
    {
        //echo $leafId;
        //print_r($user->toArray());
        $permissions = ExplorernodePermission::where('permissioned_user_id', Config::get('user_id'))->where('leaf_id', $leafId)->first();
        if ($permissions) {
            return $permissions;
        } else {
            return true;
        }
    }

    /**
     * Function to delete explorer node
     * @param $leafId
     * @param $leafType
     * @param $isParent
     * @param null $user
     */
    public function deleteExplorernodeAccordingToPermissions($leafId, $leafType, $isParent, $user = null)
    {
        //$user = is_null($user) ? User::current() : $user;
        //$userObject = array('id' => Config::get('user_id'), 'customer_id', Config::get('customer_id'));
        $allNodes = ExplorernodePermission::where('leaf_id', $leafId)->where('leaf_type', $leafType)->get();
        foreach ($allNodes as $node) {
            if ($leafType == 'asset') {
                $asset = Asset::where('id', $node->child_id)->first();
                if ($asset) {
                    \Event::fire(new AssetDeletedEvent($asset));
                    if(config('app.elastic_search')) {
                        $seedToElastic = new SeedToElastic($user->id);
                        $seedToElastic->removeExplorerNode($asset);
                        $seedToElastic->removeAsset($asset->id);
                    }
                    $asset->delete();
                    //Notification::notifyCurrentUserFriends($user->id, $user->customer_id_hash, 'Asset removed');
                    Notification::assetNotifications($user->id, 'Asset removed');
                }
            } else if ($leafType == 'product') {
                $product = Product::where('id', $node->child_id)->first();
                if ($product) {
                    //$product->toHistory();
                    \Event::fire(new RemoveProductEvent($product));
                    if(config('app.elastic_search'))
                    {
                        (new SeedToElastic($user))->removeExplorerNode($product);
                    }
                    $product->delete();
                    Notification::notifyCurrentUserFriends($user->id, $user->customer_id_hash, 'Product removed');
                }
            } else if ($leafType == 'managecustomer') {
                $customer = ManageCustomer::where('id', $node->child_id)->first();
                if ($customer) {
                    if(config('app.elastic_search')){
                        (new SeedToElastic($user))->removeExplorerNode($customer);
                    }
                    $customer->delete();
                    Notification::notifyCurrentUserFriends($user->id, $user->customer_id_hash, 'Customer removed');
                }
            } else if ($leafType == 'folder') {
                $folder = Folder::where('id', $node->child_id)->first();
                $folder->deleteFolderData($folder->id,$user);
                if(config('app.elastic_search'))
                {
                    (new SeedToElastic($user))->removeExplorerNode($folder);
                }
                $folder->delete();
                //Notification::notifyCurrentUserFriends($user->id, $user->customer_id_hash, 'Folder removed');
                Notification::assetNotifications($user->id, 'Folder removed');
            }
        }
        //Delete Parent
        if ($leafType == 'asset') {
            $asset = Asset::where('id', $leafId)->first();
            if ($asset) {
                \Event::fire(new AssetDeletedEvent($asset));
                if(config('app.elastic_search')) {
                    $seedToElastic = new SeedToElastic($user->id);
                    $seedToElastic->removeExplorerNode($asset);
                    $seedToElastic->removeAsset($asset->id);
                }
                $asset->delete();
                //Notification::notifyCurrentUserFriends($user->id, $user->customer_id_hash, 'Asset removed');
                Notification::assetNotifications($user->id, 'Asset removed');
            }
        } else if ($leafType == 'product') {
            $product = Product::where('id', $leafId)->first();
            if ($product) {
                $productExploreNode = Explorernode::createExplorernode($product);
                $productPrentExplorerNode = $productExploreNode->parent();
                //$product->toHistory();
                \Event::fire(new RemoveProductEvent($product));
                if(config('app.elastic_search')){
                    (new SeedToElastic($user->id))->removeExplorerNode($product);
                }
                $product->delete();
                Notification::notifyCurrentUserFriends($user->id, $user->customer_id_hash, 'Product removed');
            }
        } else if ($leafType == 'managecustomer') {
            $customer = ManageCustomer::where('id', $leafId)->first();
            if ($customer) {
                if(config('app.elastic_search')){
                    (new SeedToElastic($user->id))->removeExplorerNode($customer);
                }
                $customer->delete();
                Notification::notifyCurrentUserFriends($user->id, $user->customer_id_hash, 'Customer removed');
            }
        } else if ($leafType == 'folder') {
            $folder = Folder::where('id', $leafId)->first();
            $folder->deleteFolderData($folder->id, $user);
            (new SeedToElastic($user->id))->removeExplorerNode($folder);
            $folder->delete();
            //Notification::notifyCurrentUserFriends($user->id, $user->customer_id_hash, 'Folder removed');
            Notification::assetNotifications($user->id, 'Folder removed');
        }
    }

    /**
     * @param $leafId
     * @param $leafType
     * @return mixed
     */
    public function getAllChildNodesByLeafIdAndLeafType($leafId, $leafType)
    {
        $allNodes = ExplorernodePermission::where('leaf_id', $leafId)->where('leaf_type', $leafType)->get();
        return $allNodes;
    }
}
