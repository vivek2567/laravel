<?php
namespace App;
use Illuminate\Support\Facades\Session;
use Jenssegers\Model\Model as JenssegersModel;
use DB;
use App\Folder;
use App\Folderschema;
use App\Asset;
use App\Project;
use App\Product;
use App\Addressbook;
use App\Socialmediaaccount;
use App\Object2Object;
use App\Exceptions\APIException;
use App\Asset2Product;
use App\SMShare;
use App\TemplateIndustry;
use App\Http\Helpers\HashHelper;
use Config;


class Explorernode extends JenssegersModel {
    use Traits\SaltedFieldsForJsonTrait;
    use Traits\ActsAsAccessableTrait;
    protected $saltedFields = array('id', 'leaf_id', 'customer_profile_id');
    // the leaf object (auto-stored to reduce queries)
    private $_leafObject = null;
    // the relation object (auto-stored to reduce queries)
    private $_relationObject = null;
    // the unpacket value (this will be auto-called, if accessing a empty value)
    private $_unpacked = false;

    // this function is called by the laravel-code
    // we use this, to generate a pseudo ExplorerNodeArray
    public function attributesToArray() {
        return $this->makeExplorernodeArray();
    }

    // don't use any relation-data
    public function relationsToArray() {
        #return $this->leaf->relationsToArray();
        return [];
    }

    public function children($user = null, $ignorePermissions = false) {
        // stored child nodes
        $childNodes = [];
        $senderRelations = $this->leaf->senderRelations()->get();
        foreach ($senderRelations as $cChild) {
            $childObject = $cChild->receiverObject();
            if (!$childObject) {
                continue;
            }
            $childNodes[] = self::createExplorernode($childObject, $cChild);
        }
        return $childNodes;
    }

    public function parent($user = null, $ignorePermissions = false) {
        $parentObject = $this->leaf->firstReceiverRelation();
        if (!$parentObject) {
            return null;
        }
        $parentSenderObject = $parentObject->senderObject();
        if (!$parentSenderObject) {
            return null;
        }
        return self::createExplorernode($parentSenderObject);
    }

    public function getBreadcrumbAttribute() {
        // stored parent nodes
        #$parentNodes = [];
        #$parentNodes[] = $this;
        $parentNode = $this->parent();
        if ($parentNode) {
            $parentNodes = array_reverse($parentNode->breadcrumb);
            array_push($parentNodes, $this);
        }
        else {
            $parentNodes = [$this];
        }
        return array_reverse($parentNodes);
    }

    public function fetchBreadcrumbs($user) {
        $parentNode = $this->parent($user);
        if ($parentNode) {
            $parentNodes = array_reverse($parentNode->fetchBreadcrumbs($user));
            array_push($parentNodes, $this);
        }
        else {
            $parentNodes = [$this];
        }
        return array_reverse($parentNodes);
    }

    public function getLeafAttribute() {
        if ($this->_leafObject != null) {
            return $this->_leafObject;
        }
        if ($this->leaf_id == null || $this->leaf_type == null) {
            return null;
        }
        $encodedLeafId = self::encodeId($this->leaf_type, $this->leaf_id);
        $this->leaf = self::findById($encodedLeafId);
        return $this->_leafObject;
    }

    public function setLeafAttribute($leaf) {
        $this->_leafObject = $leaf;
    }

    public function setRelationObjectAttribute($relation) {
        $this->_relationObject = $relation;
    }

    public function getRelationObjectAttribute() {
        return $this->_relationObject;
    }

    // we overwrite the 'ActsAsAccessableTrait' functions here, to link to the leaf object
    public function accessedRights() {
        return $this->leaf->accessedRights();
    }

    // we overwrite the 'ActsAsAccessableTrait' functions here, to link to the leaf object
    public function receivedRights() {
        return $this->leaf->receivedRights();
    }

    public function getCapabilities($params, $user) {
        $current = self::findById($params, $user);
        $this->checkIfVisible($current);
        $capabilities = $current->getObjectCapabilities();
        return $capabilities;
    }

    public static function findById($explorerNodeId, $user = null) {
        $findScope = self::createFindScope($explorerNodeId, $user);
        if (!$findScope) {
            return null;
        }
        $leafObject = $findScope->first();
        if (!$leafObject) {
            return null;
        }
        return self::createExplorernode($leafObject);
    }

    public static function findAllById($explorerNodeId = null, $user = null) {
        $findScope = self::createFindScope($explorerNodeId, $user);
        if (!$findScope) {
            return [];
        }
        $retData = [];
        foreach ($findScope->get() as $leafObject) {
            $retData[] = self::createExplorernode($leafObject);
        }
        return $retData;
    }

    public static function createFindScope($explorerNodeId, $user = null) {
        (($user == null) ? $user = User::current() : null);
        $decodedIdData = self::decodeId($explorerNodeId);
        $findScope = null;
        switch ($decodedIdData['class']) {
            case 'asset' :
                $findScope = Asset::where('customer_id', Config::get('customer_id'))->where($decodedIdData['field'], $decodedIdData['operator'], $decodedIdData['value']);
                break;
            case 'project' :
                $findScope = Project::where('user_id', Config::get('user_id'))->where($decodedIdData['field'], $decodedIdData['operator'], $decodedIdData['value']);
                break;
            case 'product' :
                $findScope = Product::where('user_id', Config::get('user_id'))->where($decodedIdData['field'], $decodedIdData['operator'], $decodedIdData['value']);
                break;
            case 'socialmediaaccount' :
                // only show user's socialmediaaccount
                $findScope = Socialmediaaccount::where($decodedIdData['field'], $decodedIdData['operator'], $decodedIdData['value'])->where('user_id', Config::get('user_id'));
                break;
            case 'folder' :
                $findScope = Folder::where('user_id', Config::get('user_id'))->where($decodedIdData['field'], $decodedIdData['operator'], $decodedIdData['value']);
                break;
            case 'managecustomer' :
                $findScope = ManageCustomer::where('user_id', Config::get('user_id'))->where($decodedIdData['field'], $decodedIdData['operator'], $decodedIdData['value']);
                break;
            case 'Report' :
                $findScope = Report::where('user_id', Config::get('user_id'))->where($decodedIdData['field'], $decodedIdData['operator'], $decodedIdData['value']);
                break;

            default :
                break;
        }
        return $findScope;
    }

    public function rename($newName, $user = null) {
        (($user == null) ? $user = User::current() : null);
        if (!$this->hasPermissionTo(Accessright::ACCESS_PERMISSION_RENAME, $user)) {
            return false;
        }
        switch ($this->leaf->getClassName()) {
            case 'Asset' :
                $this->leaf->name = $newName;
                break;
            case 'Project' :
                $this->leaf->name = $newName;
                break;
            case 'Product' :
                $this->leaf->name = $newName;
                break;
            case 'Socialmediaaccount' :
                $this->leaf->name = $newName;
                break;
            case 'Customer' :
                $this->leaf->name = $newName;
                break;
            case 'Folder' :
                $this->leaf->name = $newName;
                break;
            case 'ManageCustomer' :
                $this->leaf->name = 'customer';
                break;
            case 'Report' :
                $this->leaf->name = 'Report';
                break;
        }
        $this->leaf->save();
        $this->refreshLeaf();
        return true;
    }

    public static function createExplorernode($leafObject, $relationObject = null) {
        return new Explorernode(['leaf' => $leafObject, 'relationObject' => $relationObject]);
    }

    public static function decodeId($encodedId) {
        $encodedData = ['value' => null, 'field' => null, 'operator' => null, 'class' => null];
        // toDo de-hash here..
        if ($encodedId == '') {
            $encodedData = ['value' => '^' . preg_quote(Folderschema::INTERNAL_NAME_PREFIX) . "[a-z]+" . preg_quote(Folderschema::INTERNAL_NAME_TOKEN) . "root$", 'field' => 'internal_name', 'operator' => 'REGEXP', 'class' => 'folder'];
        }
        elseif (substr($encodedId, 0, 1) == ':') {
            $encodedData = ['value' => $encodedId, 'field' => 'internal_name', 'operator' => '=', 'class' => 'folder'];
        }
        elseif (strpos($encodedId, '.') !== false) {
            list($encodedData['class'], $encodedData['value']) = explode('.', $encodedId);
            $encodedData['field'] = 'id';
            $encodedData['operator'] = '=';
            // secure-check for integer value
            if (!is_numeric($encodedData['value'])) {
                $encodedData = ['value' => $encodedId, 'field' => 'internal_name', 'operator' => '=', 'class' => 'folder'];
            }
        }
        elseif (is_numeric($encodedId)) {
            $encodedData = ['value' => $encodedId, 'field' => 'id', 'operator' => '=', 'class' => 'folder'];
        }
        return $encodedData;
    }

    public static function encodeId($object, $objectId = null) {
        if (!is_object($object)) {
            return strtolower($object) . "." . $objectId;
        }
        return strtolower($object->getClassName()) . "." . $object->id;
    }

    public function getValidationErrorMessages() {
        return $this->leafe->getValidationErrorMessages();
    }

    public function __get($key) {
        $res = parent::__get($key);
        if ($res == null && (!$this->_unpacked) && (!in_array($key, ['leaf', 'relationObject']))) {
            $this->unpackLeaf();
            return parent::__get($key);
        }
        return $res;
    }

    public function unpackLeaf() {
        if ((!$this->_leafObject) || $this->_unpacked) {
            return false;
        }
        $enArray = $this->makeExplorernodeArray();
        $this->fill($enArray);
        $this->_unpacked = true;
    }

    public function refreshLeaf() {
        if (!$this->_leafObject) {
            return false;
        }
        $this->_leafObject = $this->_leafObject->fresh();
        unset($this->leafe);
    }

    // this will create the pseudo explorernode array (used in API)
    private function makeExplorernodeArray() {
        $explorerNodeData = ['id' => null, 'name' => null, 'internal_name' => null, 'capabilities' => [], 'description' => null, 'leaf_type' => null, 'leaf_id' => null, 'type' => null, 'icon' => null, 'size' => null, 'thumbUrl' => null, 'author' => [],];

        $leafObject = $this->leaf;
        if (!$leafObject) {
            return null;
        }
        // get leaf type by custom leaf 'getClassName' function
        if ($leafObject->internal_name == ':products_manage_root') {
            $leafType = 'products';
        }
        elseif ($leafObject->internal_name == ':customers_manage_root') {
            $leafType = 'customers';
        }
        elseif ($leafObject->internal_name == ':project_manage_root') {
            $leafType = 'projects';
        }
        elseif ($leafObject->internal_name == ':assets_manage_root') {
            $leafType = 'assets';
        }
        else {
            $leafType = strtolower($leafObject->getClassName());
        }
        $explorerNodeData['id'] = self::encodeId($leafObject);
        if (method_exists($leafObject, 'getThumbURLAttribute')) {
            $explorerNodeData['thumbUrl'] = $leafObject->getThumbURLAttribute();
        }
        $explorerNodeData['created_at'] = (String) $leafObject->created_at;
        $explorerNodeData['updated_at'] = (String) $leafObject->updated_at;
        /*$author = $leafObject->user;
        if ($author) {
            $explorerNodeData['author'] = $author->toArray(['name', 'avatarURL']);
        }*/
        $explorerNodeData['leaf_type'] = $leafType;
        $explorerNodeData['leaf_id'] = $leafObject->id;
        $explorerNodeData['nodestatus'] = $leafObject->nodestatus;
        // toDo: maybe activate those permissions
        //$explorerNodeData['permissions'] = $leafObject->permissions;
        if ($this->relationObject) {
            $explorerNodeData['displayorder'] = $this->relationObject->displayorder;
        }
        if ($leafObject->internal_name) {
            $explorerNodeData['internal_name'] = $leafObject->internal_name;
        }
        if ($leafObject->parent_original) {
            $explorerNodeData['parent_original'] = $leafObject->parent_original;
        }
        if ($leafObject->capabilities) {
            $explorerNodeData['capabilities'] = $leafObject->capabilities;
        }
        switch ($leafType) {
            case 'asset' :
                $explorerNodeData['name'] = $leafObject->name;
                $explorerNodeData['description'] = $leafObject->description;
                $explorerNodeData['type'] = 'leaf';
                $explorerNodeData['size'] = $leafObject->attachmentsFilsize();
                $explorerNodeData['icon'] = $leafObject->icon;
                $explorerNodeData['use_in_creator'] = $leafObject->use_in_creator;
                break;
            case 'project' :
                $projectObj = new Project();
                $explorerNodeData['name'] = $leafObject->name;
                $explorerNodeData['type'] = 'leaf';
                $explorerNodeData['icon'] = 'barcode';
                $explorerNodeData['size'] = $projectObj->getProjectSize($explorerNodeData['leaf_id']);
                break;
            case 'product' :
                $productObject = new Product();
                $explorerNodeData['name'] = $leafObject->name;
                $explorerNodeData['description'] = $leafObject->description;
                $explorerNodeData['type'] = 'inner';
                $explorerNodeData['icon'] = 'barcode';
                // $explorerNodeData['size'] = $productObject->getProductSize($explorerNodeData['leaf_id']);
                break;
            case 'socialmediaaccount' :
                $explorerNodeData['name'] = $leafObject->name;
                $explorerNodeData['type'] = 'leaf';
                $explorerNodeData['icon'] = 'clipboard-account';
                break;
            case 'customer' :
                $explorerNodeData['name'] = $leafObject->name;
                $explorerNodeData['type'] = 'leaf';
                $explorerNodeData['icon'] = 'clipboard-account';
                break;
            case 'folder' :
                $folderObj = new Folder();
                $explorerNodeData['name'] = $leafObject->name;
                $explorerNodeData['description'] = $leafObject->description;
                $explorerNodeData['type'] = $this->getType($leafObject);
                $explorerNodeData['icon'] = 'archive';
                $explorerNodeData['size'] = $folderObj->getFolderSize($explorerNodeData['leaf_id']);
                $explorerNodeData['industry'] = $this->getTemplateIndustry($explorerNodeData['leaf_id']);
                break;
            case 'products' :
                $explorerNodeData['name'] = $leafObject->name;
                $explorerNodeData['description'] = $leafObject->description;
                $explorerNodeData['type'] = $this->getType($leafObject);
                $explorerNodeData['icon'] = 'archive';
                break;
            case 'customers' :
                $explorerNodeData['name'] = $leafObject->name;
                $explorerNodeData['description'] = $leafObject->description;
                $explorerNodeData['type'] = $this->getType($leafObject);
                $explorerNodeData['icon'] = 'archive';
                break;
            case 'projects' :
                $explorerNodeData['name'] = $leafObject->name;
                $explorerNodeData['description'] = $leafObject->description;
                $explorerNodeData['type'] = $this->getType($leafObject);
                $explorerNodeData['icon'] = 'archive';
                break;
            case 'assets' :
                $explorerNodeData['name'] = $leafObject->name;
                $explorerNodeData['description'] = $leafObject->description;
                $explorerNodeData['type'] = $this->getType($leafObject);
                $explorerNodeData['icon'] = 'archive';
                break;
            case 'report' :
                $explorerNodeData['name'] = $leafObject->name;
                $explorerNodeData['description'] = $leafObject->description;
                $explorerNodeData['data_id'] = $leafObject->data_id;
                break;
            case 'managecustomer' :
                $explorerNodeData['name'] = $leafObject->company;
                $explorerNodeData['company'] = $leafObject->company;
                $explorerNodeData['occupation'] = $leafObject->occupation;
                $explorerNodeData['description'] = $leafObject->description;
                $explorerNodeData['type'] = $this->getType($leafObject);
                $explorerNodeData['icon'] = 'archive';
                $explorerNodeData['customer_profile_id'] = $leafObject->customer_profile_id;
                break;
            default :
                $explorerNodeData['name'] = $leafObject->name;
                $explorerNodeData['icon'] = 'archive';
                break;
        }
        return $explorerNodeData;
    }

    private function getType($object) {
        $parent = $object->receiverRelations()->count();
        if ($parent > 0) {
            return 'inner';
        }
        return 'root';
    }

    /** CCC secion explorer */
    public static function findByIds($explorerNodeId, $user = null) {
        $findScope = self::createFindScopes($explorerNodeId, $user);
        if (!$findScope) {
            return null;
        }
        $leafObject = $findScope->first();
        if (!$leafObject) {
            return null;
        }
        return self::createExplorernode($leafObject);
    }

    public static function createFindScopes($explorerNodeId, $user = null) {
        $decodedIdData = self::decodeId($explorerNodeId);

        $findScope = null;
        switch ($decodedIdData['class']) {
            case 'asset' :
                $findScope = Asset::where($decodedIdData['field'], $decodedIdData['operator'], $decodedIdData['value']);
                break;
            case 'product' :
                $findScope = Product::where($decodedIdData['field'], $decodedIdData['operator'], $decodedIdData['value']);
                break;
            case 'folder' :
                $findScope = Folder::where($decodedIdData['field'], $decodedIdData['operator'], $decodedIdData['value']);
                break;
            default :
                break;
        }
        return $findScope;
    }

    public function makeLeafAsset($asset) {
        $explorerNodeData = ['id' => null, 'name' => null, 'internal_name' => null, 'capabilities' => [], 'description' => null, 'leaf_type' => null, 'leaf_id' => null, 'type' => null, 'icon' => null, 'size' => null, 'thumbUrl' => null, 'author' => [],];
        $leafObject = $asset;
        if (!$leafObject) {
            return null;
        }
        $leafType = 'asset';
        $explorerNodeData['id'] = self::encodeId('asset.' . $leafObject->id);
        if (method_exists($leafObject, 'getThumbURLAttribute')) {
            $explorerNodeData['thumbUrl'] = $leafObject->getThumbURLAttribute();
        }
        $explorerNodeData['created_at'] = (String) $leafObject->created_at;
        $explorerNodeData['updated_at'] = (String) $leafObject->updated_at;
        $explorerNodeData['display_order'] = 0;
        /*$author = $leafObject->user;
        if ($author) {
            $explorerNodeData['author'] = $author->toArray(['name', 'avatarURL']);
        }*/
        $explorerNodeData['leaf_type'] = $leafType;
        $explorerNodeData['leaf_id'] = self::saltedEncode($leafObject->id);
        // toDo: maybe activate those permissions
        //$explorerNodeData['permissions'] = $leafObject->permissions;
        if ($leafObject->internal_name) {
            $explorerNodeData['internal_name'] = $leafObject->internal_name;
        }
        if ($leafObject->capabilities) {
            $explorerNodeData['capabilities'] = $leafObject->capabilities;
        }
        $explorerNodeData['name'] = $leafObject->name;
        $explorerNodeData['description'] = $leafObject->description;
        //$explorerNodeData['type'] = 'customer';
        $explorerNodeData['size'] = $leafObject->attachmentsFilsize();
        $explorerNodeData['icon'] = $leafObject->icon;
        $explorerNodeData['use_in_creator'] = $leafObject->use_in_creator;
        return $explorerNodeData;
    }

    public function makeCMLeafAsset($asset) {
        $explorerNodeData = ['id' => null, 'name' => null, 'internal_name' => null, 'capabilities' => [], 'description' => null, 'leaf_type' => null, 'leaf_id' => null, 'type' => null, 'icon' => null, 'size' => null, 'thumbUrl' => null, 'author' => [], 'isCMAsset' => true,];
        $leafObject = $asset;
        if (!$leafObject) {
            return null;
        }
        $leafType = 'asset';
        $explorerNodeData['id'] = self::encodeId('asset.' . $leafObject->id);
        if (method_exists($leafObject, 'getThumbURLAttribute')) {
            $explorerNodeData['thumbUrl'] = $leafObject->getThumbURLAttribute();
        }
        $explorerNodeData['created_at'] = (String) $leafObject->created_at;
        $explorerNodeData['updated_at'] = (String) $leafObject->updated_at;
        $explorerNodeData['display_order'] = 0;
        /*$author = $leafObject->user;
        if ($author) {
            $explorerNodeData['author'] = $author->toArray(['name', 'avatarURL']);
        }*/
        $explorerNodeData['leaf_type'] = $leafType;
        $explorerNodeData['leaf_id'] = self::saltedEncode($leafObject->id);
        // toDo: maybe activate those permissions
        //$explorerNodeData['permissions'] = $leafObject->permissions;
        if ($leafObject->internal_name) {
            $explorerNodeData['internal_name'] = $leafObject->internal_name;
        }
        if ($leafObject->capabilities) {
            $explorerNodeData['capabilities'] = $leafObject->capabilities;
        }
        $explorerNodeData['name'] = $leafObject->name;
        $explorerNodeData['description'] = $leafObject->description;
        $explorerNodeData['type'] = 'leaf';
        $explorerNodeData['size'] = $leafObject->attachmentsFilsize();
        $explorerNodeData['icon'] = $leafObject->icon;
        $explorerNodeData['use_in_creator'] = $leafObject->use_in_creator;
        return $explorerNodeData;
    }

    public static function findNodeById($explorerNodeId, $user = null) {
        $findScope = self::createFindScope($explorerNodeId, $user);
        if (!$findScope) {
            return null;
        }
        $leafObject = $findScope->first();
        if (!$leafObject) {
            return null;
        }
        return $leafObject;
    }

    private function getTemplateIndustry($leafId){
        $industry = TemplateIndustry::where('template_id', $leafId)->get()->first();
        if($industry){
            return HashHelper::encodeId($industry->industry_id, config('app.salt'));
        }else{
            return '';
        }
    }
}