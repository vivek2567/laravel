<?php
/**
 * Created by PhpStorm.
 * User: Mohit Dhiman
 * Date: 12/29/2016
 * Time: 11:34 AM
 */

namespace App\Services;
use App\Exceptions\APIException;
use App\User2Usergroup;
use Log;
use Carbon\Carbon;
use App\Addressbook;
use App\Asset;
use App\Explorernode;
use App\Folder;
use App\ObjectPermission;
use App\Product;
use App\Project;
use App\Socialmediaaccount;
use App\Http\Helpers\HashHelper;
use Elasticsearch\ClientBuilder;
//use PMA\libraries\Config;
use Config;
use App\User;


/**
 * Class SeedToElastic
 * @package App\Services
 */
class SeedToElastic {

    /**
     * Specify the elastic index
     */
    const ELASTIC_INDEX = 'explorernodes';

    /**
     * Specify the type for elastic explorer nodes
     */
    const ELASTIC_INDEX_TYPE_NODE = 'node';

    /**
     * Specify the type for the elastic user
     */
    const ELASTIC_INDEX_TYPE_AUTHOR = 'author';

    /**
     * Specify the photo assets type
     */
    const ELASTIC_INDEX_TYPE_PHOTOS_ASSETS = 'photos_assets_create_root';

    /**
     * Specify the vectors assets type
     */
    const ELASTIC_INDEX_TYPE_VECTORS_ASSETS = 'vectors_assets_create_root';

    /**
     * Specify the assets type
     */
    const ELASTIC_INDEX_TYPE_ASSETS = 'assets';

    /**
     * @var \Elastic search\Client
     * @description Handles the current instance  of the elastic search client
     */
    protected $client;

    /**
     * @var \App\User
     * @description Handles the current user instance
     */
    protected $user;

    /**
     * @var
     * @description contains the current user permissions
     */
    protected $permissions;

    /**
     * @var
     * @description contains the current user access permissions
     */
    protected $accessPermissions;

    /**
     * @var
     * @description contains the current user's all explorer nodes
     */
    protected $nodes;

    /**
     * @var
     * @description contains the current user's all explorer nodes
     */
    protected $unique_explorer_nodes;

    /**
     * @var array
     * @description contains the current user's folder explorer nodes
     */
    protected $folderList = [];

    /**
     * SeedToElastic constructor.
     * @param $user
     * @description invoke the seeder with the user
     */
    public function __construct($user)
    {
        $this->client =  ClientBuilder::create()
            ->setHosts([config('services.elastic_hosts')])
            ->build();



        if(isset($user)) {

            $this->user = $user;

        } else {

            $this->user = Config::get('user_id');
        }

        $userArray = (object)array(
            'id' => $this->user
        );

        $this->currentUser = $userArray;

        $this->unique_explorer_nodes = [];

        $this->folderList = [];
    }

    /**
     * @return bool
     * @description removes the elastic index for nodes
     */
    public function removeIndex()
    {
        try {
            $params = ['index' => static::ELASTIC_INDEX];
            $response = $this->client->indices()->delete($params);
            return isset($response['acknowledged']) ? $response['acknowledged'] : false;
        } catch (\Exception $e){
            return false;
        }
    }

    /**
     * @param $id
     * @return string
     * @description creates a hash for the provided id
     */
    protected function hash($id)
    {
        return HashHelper::encodeId($id, config('app.salt'));
    }

    /**
     * @return bool
     * @description checks for the elastic connection
     */
    protected function checkForConnection()
    {
        try {
            $this->client->info();
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * @return array
     * @description provides the mapping for the elastic index
     */
    protected function fetchIndexMapping()
    {
        $dateNullValue = $this->fetchDateFormatted(null);

        return [
            'index' => static::ELASTIC_INDEX,
            'update_all_types' => true,
            'body' => [
                'mappings' => [
                    static::ELASTIC_INDEX_TYPE_NODE => [
                        'properties' => [
                            'customer_profile_id' => [
                                'type' => 'string',
                                'index' => 'not_analyzed'
                            ],
                            'parent_id' => [
                                'type' => 'keyword',
                            ],
                            'parent_internal_name' => [
                                'type' => 'keyword',
                            ],
                            'id' => [
                                'type' => 'string'
                            ],
                            'author_id' => [
                                'type' => 'string',
                                'index' => 'not_analyzed'
                            ],
                            'name' => [
                                'type' => 'string',
                                'analyzer' => 'autocomplete_analyzer',
                                'search_analyzer' => 'standard',
                                'fielddata' => true
                            ],
                            'internal_name' => [
                                'type' => 'string',
                                'index' => 'not_analyzed'
                            ],
                            'capabilities' => [
                                'type' => 'nested'
                            ],
                            'description' => [
                                'type' => 'string',
                                'index' => 'not_analyzed'
                            ],
                            'leaf_type' => [
                                'type' => 'string',
                                'index' => 'not_analyzed'
                            ],
                            'leaf_id' => [
                                'type' => 'string'
                            ],
                            'type' => [
                                'type' => 'string',
                                'index' => 'not_analyzed'
                            ],
                            'icon' => [
                                'type' => 'string',
                                'index' => 'not_analyzed'
                            ],
                            'size' => [
                                'type' => 'string',
                                'index' => 'not_analyzed'
                            ],
                            'thumbUrl' => [
                                'type' => 'string',
                                'index' => 'not_analyzed'
                            ],
                            'created_at' => [
                                'type' => 'date',
                                #'format' => 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||epoch_millis',
                                'null_value' => 'NULL'
                            ],
                            'updated_at' => [
                                'type' => 'date',
                                'null_value' => 'NULL'
                            ],
                            /*'displayorder' => [
                                'type' => 'integer'
                            ],*/
                            'use_in_creator' => [
                                'type' => 'integer',
                                'index' => 'not_analyzed'
                            ],
                            'nodestatus' => [
                                'type' => 'keyword'
                            ],
                            'industry' => [
                                'type' => 'keyword',
                                'null_value' => 'NULL'
                            ]
                        ]
                    ],
                    static::ELASTIC_INDEX_TYPE_AUTHOR => [
                        'properties' => [
                            'id' => [
                                'type' => 'string',
                                #'index' => 'not_analyzed'
                            ],
                            'name' => [
                                'type' => 'string',
                                'analyzer' => 'autocomplete_analyzer',
                                'search_analyzer' => 'standard',
                                'fielddata' => true
                            ],
                            'avatarURL' => [
                                'type' => 'string',
                                'index' => 'not_analyzed'
                            ],
                            'is_customer_admin' => [
                                'type' => 'boolean'
                            ],
                            'permissions' => [
                                'type' => 'object'
                            ],
                            'access_permissions' => [
                                'type' => 'object'
                            ]
                        ]
                    ],
                    static::ELASTIC_INDEX_TYPE_ASSETS => [
                        'properties' => [

                            'validTo' => [
                                'type' => 'date',
                                #'format' => 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||epoch_millis',
                                'null_value' => $dateNullValue
                            ],

                            'validFrom' => [
                                'type' => 'date',
                                #'format' => 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||epoch_millis',
                                'null_value' => $dateNullValue
                            ],

                            'touched_at' => [
                                'type' => 'date',
                                #'format' => 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||epoch_millis',
                                'null_value' => $dateNullValue
                            ],

                            'deleted_at' => [
                                'type' => 'date',
                                #'format' => 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||epoch_millis',
                                'null_value' => $dateNullValue
                            ],

                            'updated_at' => [
                                'type' => 'date',
                                #'format' => 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||epoch_millis',
                                'null_value' => $dateNullValue
                            ],

                            'created_at' => [
                                'type' => 'date',
                                #'format' => 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||epoch_millis',
                                'null_value' => $dateNullValue
                            ],

                            'type_enumeration_number' => [
                                'type' => 'keyword',
                            ],

                        ]
                    ],
                    static::ELASTIC_INDEX_TYPE_PHOTOS_ASSETS => [
                        'properties' => [
                            'id' => [
                                'type' => 'string'
                            ]
                        ]
                    ],
                    static::ELASTIC_INDEX_TYPE_VECTORS_ASSETS => [
                        'properties' => [
                            'id' => [
                                'type' => 'string'
                            ]
                        ]
                    ],
                ],
                'settings' => [
                    'analysis' => [
                        'filter' => [
                            'autocomplete_filter' => [
                                'type' => 'edge_ngram',
                                'min_gram' => 1,
                                'max_gram' => 20,
                                'side' => 'front'
                            ]
                        ],
                        'tokenizer' => [
                            'autocomplete_tokenizer' => [
                                'type' => 'edgeNGram',
                                'min_gram' => 1,
                                'max_gram' => 10,
                                'token_chars' => [
                                    'letter',
                                    'digit'
                                ]
                            ]
                        ],
                        'analyzer' => [
                            'autocomplete_analyzer' => [
                                'type' => 'custom',
                                'tokenizer' => 'autocomplete_tokenizer',
                                'filter' => [
                                    'lowercase',
                                    'asciifolding',
                                    'autocomplete_filter'
                                ]
                            ],
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * @return mixed
     * @description assign the access permissions for the current user
     */
    protected function assignAccessPermissions()
    {
        if(isset($this->accessPermissions)){
            return $this->accessPermissions;
        }

        $currentUser = new User;
        $currentUser->id = $this->user;
        $currentUser->user_id = $this->user;

        $this->accessPermissions = $currentUser->getPermissionsFor($this->user);

        return $this->accessPermissions;
    }

    /**
     * @return mixed|\stdClass
     * @description assign the permissions for the current user
     */
    protected function assignPermissions()
    {
        if(isset($this->permissions)){
            return $this->permissions;
        }

        $objectPermissions = ObjectPermission::where('object_id', Config::get('user_id'))->where('object_type', 'user')->first();

        if(is_null($objectPermissions)){
            $this->permissions = new \stdClass();
        } else {
            $this->permissions = json_decode($objectPermissions->permissions);
        }

        return  $this->permissions;
    }

    /**
     * @param $explorerNode
     * @return array
     * @description provides the instance of the explorer node
     */
    public function fetchNode($explorerNode)
    {
        try
        {
            $explorerNodeList = $explorerNode->toArray();

            $explorerNodeList['id'] = $this->hash($explorerNode->id);

            $explorerNodeList['author_id'] = HashHelper::encodeId($this->user, config('app.salt'));

            $explorerNodeList['reference_id'] = $explorerNode->internal_name;

            $explorerNodeList['created_at'] = Carbon::createFromFormat('Y-m-d H:i:s', $explorerNode->created_at)->toIso8601String();

            $explorerNodeList['updated_at'] = Carbon::createFromFormat('Y-m-d H:i:s', $explorerNode->updated_at)->toIso8601String();

            $explorerNodeList['author'] = [
//                'name' => $this->user->name,
//                'avatarURL' => $this->user->avatarURL,
                'name' => 'user',

                'avatarURL' => 'default.png',
            ];

            if(isset($explorerNodeList['leaf_id'])){

                $explorerNodeList['leaf_id'] = $this->hash($explorerNode->leaf_id);
            }

            $parent = $explorerNode->parent($this->user);

            if(!is_null($parent))
            {
                $explorerNodeList['parent_id'] = $this->hash($parent->id);

                $explorerNodeList['parent_internal_name'] = $parent->internal_name;
            }

            $allBreadcrumbs = [];

            $breadcrumbs = $explorerNode->fetchBreadcrumbs($this->user);

            foreach($breadcrumbs as $breadcrumb)
            {
                $allBreadcrumbs[] = $this->hash($breadcrumb->id); //$breadcrumb->id;
            }

            $explorerNodeList['breadcrumb'] = $allBreadcrumbs;

            if($explorerNode['leaf_type'] == 'managecustomer'){

                $explorerNodeList['customer_profile_id'] = $this->hash($explorerNode['customer_profile_id']);
            }

            return $explorerNodeList;
        }

        catch(\Exception $e)
        {
            $leafId = null;

            $leafType = null;

            $leaf = $explorerNode->getLeafAttribute();

            if(is_null($leaf) == false) {

                $leafId = $leaf->id;

                $leafType = get_class($leaf);

            }
            Log::error(['ELASTIC_ERROR', $leafId, $explorerNode->internal_name, $e->getMessage(), __FILE__, __LINE__,]);

            $message = $e->getMessage();

            throw new APIException('Unable to create node. Details: '.$message, 500);
        }
    }

    /**
     * @param $node
     * @return Explorernode
     */
    protected function fetchExplorerSingleInstance($node)
    {
        return Explorernode::createExplorernode($node);
    }

    /**
     * @param array $nodes
     * @return array
     * @description provides the nodes as explorer instance
     */
    protected function fetchExplorerInstances($nodes = [])
    {
        $nodesExplorer = [];

        foreach($nodes as $node){
            $generatedNode = $this->fetchExplorerSingleInstance($node); //Explorernode::createExplorernode($node);
            if(!is_null($generatedNode)){
                if(count($createdNode = $this->fetchNode($generatedNode))){
                    $nodesExplorer[] = $createdNode;
                }
            }
        }

        return $nodesExplorer;
    }

    /**
     * @return array
     * @description returns the current user's assets
     */
    protected function generateAssetExplorer()
    {
        $assets = Asset::where('user_id',Config::get('user_id'))->get()->all();

        return $this->fetchExplorerInstances($assets);
    }

    /**
     * @return array
     * @description returns the current user's projects
     */
    protected function generateProjectExplorer()
    {
        $projects = Project::where('user_id',Config::get('user_id'))->get();

        return $this->fetchExplorerInstances($projects);
    }

    /**
     * @return array
     * @description returns the current user's products
     */
    protected function generateProductExplorer()
    {
        $products = Product::where('user_id',Config::get('user_id'))->get()->all();

        return $this->fetchExplorerInstances($products);
    }

    /**
     * @return array
     * @description returns the current user's social media
     */
    protected function generateSocialMediaAccountExplorer()
    {
        $socialMediaAccounts = Socialmediaaccount::where('user_id',Config::get('user_id'))->get()->all();

        return $this->fetchExplorerInstances($socialMediaAccounts);
    }

    /**
     * @return array
     * @description returns the current user's address book
     */
    protected function generateAddressBookExplorer()
    {
        $addressBooks = Addressbook::where('user_id',Config::get('user_id'))->get()->all();

        return $this->fetchExplorerInstances($addressBooks);
    }

    /**
     * @return mixed
     * @description retrieve the folders
     */
    protected function fetchFolders()
    {
        return Folder::where('user_id', Config::get('user_id'))->get()->all();
    }

    /**
     * @param $generatedNode
     * @description fetch the child of the node
     */
    protected function fetchChildren($generatedNode)
    {
        $this->folderList[] = $generatedNode->children();

        foreach($generatedNode->children() as $child)
        {
            $this->fetchChildren($child);
        }
    }

    /**
     * @return array
     * @description generated the root node and fetch the children for seeding
     */
    public function fetchFolderListExplorerNode()
    {
        if ( $this->user) {
            $rootFolder = Folder::where('user_id', $this->user)->where('internal_name', ':root')->first();
        } else {
            $rootFolder = Folder::where('user_id', Config::get('user_id'))->where('internal_name', ':root')->first();
        }

        if($rootFolder)
        {
            $this->folderList = [];

            $generatedNode = $this->fetchExplorerSingleInstance($rootFolder);

            $this->folderList[] = [$generatedNode];

            $this->fetchChildren($generatedNode);

            $nodesExplorer = [];

            foreach($this->folderList as $keyOrder => $explorerNodes)
            {
                if(count($explorerNodes))
                {
                    foreach($explorerNodes as $explorerNode)
                    {
                        if(count($createdNode = $this->fetchNode($explorerNode)))
                        {
                            $nodesExplorer[] = $createdNode;
                        }
                    }
                }
            }

            $this->folderList = [];

            return $nodesExplorer;
        }

        return [];
    }

    /**
     * @return array
     * @description returns the current user's folders
     */
    protected function generateFolderExplorer()
    {
        //$folders = $this->fetchFolders();

        //return $this->fetchExplorerInstances($folders);

        return $this->fetchFolderListExplorerNode();
    }

    /**
     * @return array
     * @description returns the current user's all explorer nodes instances
     */
    protected function fetchFormattedData()
    {
        //$expNodes['assetData'] = $this->generateAssetExplorer();

        ////$expNodes['projectData'] = $this->generateProjectExplorer();

        //$expNodes['productData'] = $this->generateProductExplorer();

        //$expNodes['socialMediaAccountData'] = $this->generateSocialMediaAccountExplorer();

        //$expNodes['addressBookData'] = $this->generateAddressBookExplorer();

        $expNodes['folderData'] = $this->generateFolderExplorer();

        return $expNodes;
    }

    /**
     *
     */
    /*protected function getAllNodesWithNestedChildren()
    {
        $folders = $this->fetchFolders();

        foreach($folders as $folder)
        {
            $explorerInstance = $this->fetchExplorerSingleInstance($folder);

            if(!is_null($explorerInstance))
            {
                //$this->
            }
        }
    }*/

    /**
     * @return array
     * @description generates the node data
     */
    protected function generateFormattedData()
    {
        if(isset($this->nodes)){
            return $this->nodes;
        }

        $formattedExpNodes = [];

        $expNodes = $this->fetchFormattedData();

        foreach($expNodes as $expNodesKey => $expNodesValues){
            if(count($expNodesValues)){
                foreach($expNodesValues as $expNodesValue){
                    $formattedExpNodes[] = [
                        'index' => [
                            '_index' => static::ELASTIC_INDEX,
                            '_type' => static::ELASTIC_INDEX_TYPE_NODE,
                            '_id' => $expNodesValue['id'],
                        ]
                    ];

                    $formattedExpNodes[] = $expNodesValue;
                }
            }
        }

        $this->nodes = $formattedExpNodes;

        return $this->nodes;
    }

    /**
     * @description seeds the generated nodes to the elastic server
     */
    protected function seedNodes()
    {
        $expNodes = $this->fetchFormattedData();

        foreach($expNodes as $expNodesKey => $expNodesValue){
            if(count($expNodesValue)){
                //$formattedExpNodes = array_merge($formattedExpNodes,$expNodesValue);
                foreach($expNodesValue as $expNode){
                    $formattedExpNodes['body'][] = [
                        'index' => [
                            '_index' => static::ELASTIC_INDEX,
                            '_type' => static::ELASTIC_INDEX_TYPE_NODE,
                            '_id' => $expNode['id']
                        ]
                    ];
                    $formattedExpNodes['body'][] = $expNode;
                }
            }
        }

        if ($this->client->indices()->exists(['index' => static::ELASTIC_INDEX])) {
            $this->client->bulk($formattedExpNodes);
        } else {
            $indices = $this->fetchIndexMapping();
            $this->client->indices()->create($indices);
            $this->client->bulk($formattedExpNodes);
        }
    }

    /**
     * @return mixed
     * @description prepares the node data to be consumed by the elastic
     */
    protected function fetchElasticSeed()
    {
        $params['body'][] = [
            'index' => [
                '_index' => static::ELASTIC_INDEX,
                '_type' => static::ELASTIC_INDEX_TYPE_AUTHOR,
                '_id' => $this->hash($this->user),
            ]

        ];

        $params['body'][] = [
            //'name' => $this->user->name,
            //'avatarURL' => $this->user->avatarURL,
            //'permissions' => $this->assignPermissions(),
            //'access_permissions' => $this->assignAccessPermissions(),
            //'is_customer_admin' => $this->user->is_customer_admin,
        ];

        $nodes = $this->generateFormattedData();

        foreach($nodes as $node){
            $params['body'][] = $node;
        }
        return $params;
    }

    /**
     * @return array
     * @throws \Exception
     * @description returns the result for the data seeded to the elastic server
     */
    public function seed()
    {
        if(!$this->checkForConnection()){
            throw new APIException('ELASTICSEARCH_SERVER_NOT_RUNNING', 500);
        }

        $params = $this->fetchElasticSeed();

        if ($this->client->indices()->exists(['index' => static::ELASTIC_INDEX])) {
            return $this->client->bulk($params);
        }

        $indices = $this->fetchIndexMapping();

        $this->client->indices()->create($indices);

        return $this->client->bulk($params);
    }

    /**
     *
     */
    protected function fetchUserCustomers()
    {

    }

    /**
     * @param $type
     * @param $elasticType
     * @return array
     * @description fetches the data from the asset table
     */
    protected function fetchAssets($type, $elasticType)
    {
        $assets = Asset::where('bo_provider_category', $type)
            ->where('bo_provider_reference_id', '!=', '')
            ->where('bo_price_category', '!=', 4)
            ->where('public', 1)
            ->get()
            ->all();

        if(count($assets)){
            $explorerNodeObjCollection = [];
            $explorerNodeObj = new Explorernode();
            foreach ($assets as $asset){
                $expAsset = $explorerNodeObj->makeLeafAsset($asset);
                $explorerNodeObjCollection['body'][] = [
                    'index' => [
                        '_index' => static::ELASTIC_INDEX,
                        '_type' => $elasticType,
                        '_id' => $this->hash($expAsset['id'])
                    ]
                ];
                $explorerNodeObjCollection['body'][] = $expAsset;
            }
            return $explorerNodeObjCollection;
        }
        return [];
    }

    /**
     * @return array
     * @description fetches the data of asset type photo
     */
    protected function fetchPhotoAssets()
    {
        return $this->fetchAssets('Photo', static::ELASTIC_INDEX_TYPE_PHOTOS_ASSETS);
    }

    /**
     * @return array
     * @description fetches the data of asset type vector
     */
    protected function fetchVectorAssets()
    {
        return $this->fetchAssets('Vector', static::ELASTIC_INDEX_TYPE_VECTORS_ASSETS);
    }

    /**
     * @param $node
     * @return array
     * @description provides the index for the node type.
     */
    protected function fetchIndexForNode($node)
    {
        return [
            'index' => static::ELASTIC_INDEX,
            'type' => static::ELASTIC_INDEX_TYPE_NODE,
            'id' => $node['id'],
            'body' => $node
        ];
    }

    /**
     * @param $generatedNode
     * @description perform the nested updates
     */
    protected function performNestedUpdates($generatedNode)
    {
        $parentNode = $this->generateNode($generatedNode);

        //$leaf = $parentNode->getLeafAttribute();

        //Log::info(['parentNode', $leaf->id, get_class($leaf)]);

        if(count($nodes =  $parentNode->children()))
        {
            foreach($nodes as $node)
            {
                $formattedChildGeneratedNode =  $this->fetchNode($node);

                $this->apply($this->fetchIndexForNode($formattedChildGeneratedNode));

                $this->performNestedUpdates($node);
            }
        }

        /*foreach($generatedNode->children() as $childGeneratedNode){
            $formattedChildGeneratedNode =  $this->fetchNode($childGeneratedNode);
            $this->apply($this->fetchIndexForNode($formattedChildGeneratedNode));

            foreach($childGeneratedNode->children() as $grandChild){
                $formattedGrandChildGeneratedNode =  $this->fetchNode($grandChild);
                $this->apply($this->fetchIndexForNode($formattedGrandChildGeneratedNode));

                foreach($grandChild->children() as $subChild){
                    $formattedSubChild =  $this->fetchNode($subChild);
                    $this->apply($this->fetchIndexForNode($formattedSubChild));
                }
            }
        }*/
    }

    /**
     * @param $index
     * @description performs the create or update operation
     * @return array
     */
    protected function apply($index)
    {
        if(is_array($index))
        {
            Log::info(['object-to-be-seeded', HashHelper::decodeId($index['id'], config('app.salt')) /*array_except($index, 'body')*/]);
        }
        else
        {
            Log::info(['object-to-be-seeded', $index]);
        }

        try {
            if ($this->client->indices()->exists(['index' => static::ELASTIC_INDEX])) {
                try{
                    if($this->client->get(array_except($index,'body'))){
                        $updateIndex = array_except($index,'body');
                        $updateIndex['body']['doc'] = $index['body'];
                        return $this->client->update($updateIndex);
                    }
                } catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {
                    return $this->client->index($index);
                }
            } else {
                $index = $this->fetchElasticSeed();
                $indices = $this->fetchIndexMapping();
                $this->client->indices()->create($indices);
                return $this->client->index($index);
            }
        } catch (\Exception $e) {
            Log::alert('ELASTIC-'. $e->getMessage() . '-' . $e->getFile() . '-' . $e->getLine());
        }
    }

    /**
     * @param $node
     * @param bool $updateParentNode
     * @description updates the single node and checks if the parent update flag is set to true
     */
    protected function performUpdate($node, $updateParentNode = true)
    {
        $generatedNode = $this->generateNode($node);

        if(!is_null($generatedNode))
        {
            $formattedNode = $this->fetchNode($generatedNode);

            if(count($formattedNode))
            {
                $this->apply($this->fetchIndexForNode($formattedNode));
            }

            if($updateParentNode)
            {
                $parentNode = $generatedNode->parent($this->user);

                if(!is_null($parentNode))
                {
                    if(!array_key_exists($parentNode->id, $this->unique_explorer_nodes))
                    {
                        $this->unique_explorer_nodes[$parentNode->id] = $parentNode;

                        //$formattedNode = $this->fetchNode($generatedNode);

                        //$this->apply($this->fetchIndexForNode($formattedNode));
                    }
                }
            }
        }
    }

    /**
     * @param $node
     * @return Explorernode
     * @description generates the instance of the Explorernode class
     */
    protected function generateNode($node)
    {
        $explorerNodeClass =  Explorernode::class;

        return ($node instanceof $explorerNodeClass) ?  $node : Explorernode::createExplorernode($node);
    }

    /**
     * @description updates the current user permissions
     */
    public function updatePermissions()
    {
        $index = [
            'index' => static::ELASTIC_INDEX,
            'type' => static::ELASTIC_INDEX_TYPE_AUTHOR,
            'id' => $this->hash($this->user->id),
            'body' => [
                'permissions' => $this->assignPermissions()
            ]
        ];

        $this->apply($index);
    }

    /**
     * @description updates the current user
     */
    public function updateUser()
    {
        $index = [
            'index' => static::ELASTIC_INDEX,
            'type' => static::ELASTIC_INDEX_TYPE_AUTHOR,
            'id' => $this->hash($this->user->id),
            'body' => [
                'name' => $this->user->name,
                'avatarURL' => $this->user->avatarURL,
                'is_customer_admin' => $this->user->is_customer_admin,
            ]
        ];

        $this->apply($index);
    }

    /**
     * @description seeds photo assets to the elastic server
     */
    public function seedPhotoAssets()
    {
        $assets = $this->fetchPhotoAssets();

        if(count($assets)){
            $this->seedAssets($assets);
        }
    }

    /**
     * @description seeds vector assets to the elastic server
     */
    public function seedVectorAssets()
    {
        $assets = $this->fetchVectorAssets();

        if(count($assets)){
            $this->seedAssets($assets);
        }
    }

    /**
     * @param $assets
     * @description bulk seeds the provided assets
     */
    protected function seedAssets($assets)
    {
        if ($this->client->indices()->exists(['index' => static::ELASTIC_INDEX])) {
            $this->client->bulk($assets);
        } else {
            $indices = $this->fetchIndexMapping();
            $this->client->indices()->create($indices);
            $this->client->bulk($assets);
        }
    }

    /**
     * @param $asset
     * @return array
     * @description creates the back office asset as explorernode instance
     */
    protected function fetchBackOfficeAsset($asset)
    {
        $types = ['Vector'=>static::ELASTIC_INDEX_TYPE_VECTORS_ASSETS, 'Photo'=>static::ELASTIC_INDEX_TYPE_PHOTOS_ASSETS];

        $explorerNodeObj = new Explorernode();
        $expAsset = $explorerNodeObj->makeLeafAsset($asset);
        $expAsset['created_at'] = Carbon::createFromFormat('Y-m-d H:i:s', $expAsset['created_at'])->toIso8601String();
        $expAsset['updated_at'] = Carbon::createFromFormat('Y-m-d H:i:s', $expAsset['updated_at'])->toIso8601String();
        return $params = [
            'index' => static::ELASTIC_INDEX,
            'type' => $types[$asset->bo_provider_category],
            'id' => $this->hash($expAsset['id']),
            'body' => $expAsset
        ];
    }

    /**
     * @param $asset
     * @description removes the asset from the elasticserver
     */
    public function deleteBackOfficeAsset($asset)
    {
        try{
            if(in_array($asset->bo_provider_category, ['Vector','Photo'])){
                $elasticIndex = $this->fetchBackOfficeAsset($asset);
                $params = array_except($elasticIndex,'body');
                $this->client->delete($params);
                $this->removeAsset($asset->id);
            }
        } catch (\Exception $e) {
            if(!$e instanceof \Elasticsearch\Common\Exceptions\Missing404Exception){
                Log::alert('ELASTIC-'. $e->getMessage() . '-' . $e->getFile() . '-' . $e->getLine());
            }
        }
    }

    /**
     * @param $asset
     * @description updates the back office asset
     */
    public function updateBackOfficeAsset($asset)
    {
        if(in_array($asset->bo_provider_category, ['Vector','Photo'])){
            if($asset->bo_provider_reference_id != '' && $asset->bo_price_category != 4){
                if($asset->public == 1){
                    $elasticIndex = $this->fetchBackOfficeAsset($asset);
                    $this->apply($elasticIndex);
                    $this->seedAsset($asset->id);
                } else {
                    $this->deleteBackOfficeAsset($asset);
                }
            }
        }
    }

    /**
     * @param $node
     * @param bool $performNestedSeeding
     * @param bool $updateParent
     * @description Seeds the explorer node
     */

    public function seedExplorerNode($node, $performNestedSeeding = false, $updateParent = false)
    {
        $node = $node->fresh();
        $explorerNode = $this->generateNode($node);
        $elasticExplorerNode = $this->fetchNode($explorerNode);
        $elasticIndex = $this->fetchIndexForNode($elasticExplorerNode);
        $this->apply($elasticIndex);

        if($performNestedSeeding)
        {
            $this->performNestedUpdates($explorerNode);
        }

        if($updateParent)
        {
            $this->seedExplorerNode($explorerNode->parent());
        }
    }

    /**
     * @param $nodes
     * @param bool $performNestedSeeding
     * @description Seeds the multiple explorer node
     */
    public function seedExplorerNodeMultiple($nodes, $performNestedSeeding = false)
    {
        foreach($nodes as $node)
        {
            $this->seedExplorerNode($node, $performNestedSeeding);
        }
    }

    /**
     * @param $explorerNode
     * @description Finds the children of node
     */
    public function findChildren($explorerNode)
    {
        $query = [
            'index' => SeedToElastic::ELASTIC_INDEX,
            'type' => SeedToElastic::ELASTIC_INDEX_TYPE_NODE,
            'body' => [
                'query' => [
                    'bool' => [
                        'minimum_should_match' => 1,
                        'should' => [
                            [
                                'match' => [
                                    'parent_id' => $this->hash($explorerNode['id'])
                                ]
                            ],
                            [
                                'match' => [
                                    'parent_internal_name' => is_null($explorerNode['internal_name']) ? '' : $explorerNode['internal_name']
                                ]
                            ]
                        ],
                        'must' => [
                            [
                                'match' => [
                                    'author_id' => HashHelper::encodeId($this->user, config('app.salt'))
                                ],
                            ]
                        ]
                    ],
                ]
            ]
        ];

        $result = $this->client->search($query);

        $this->nodes[] = $result;

        foreach($result['hits']['hits'] as $node)
        {
            $this->findChildren($node['_source']);
        }
    }

    /**
     * @param $node
     * @param bool $nested
     * @return bool
     * @description Deletes the explorer node
     */
    public function removeExplorerNode($node, $nested = true)
    {
        try
        {
            if(!is_null($node))
            {
                $this->nodes = [];

                $explorerNode = $this->generateNode($node);

                $this->findChildren($explorerNode->toArray());

                foreach($this->nodes as $nodeResult)
                {
                    if(count($nodeResult['hits']['hits']))
                    {
                        foreach($nodeResult['hits']['hits'] as $nodes)
                        {
                            $elasticExplorerNode = [
                                'index' => $nodes['_index'],
                                'type' => $nodes['_type'],
                                'id' => $nodes['_id'],
                            ];

                            $this->client->delete($elasticExplorerNode);
                        }
                    }
                }

                $this->client->delete([
                    'index' => static::ELASTIC_INDEX,
                    'type' => static::ELASTIC_INDEX_TYPE_NODE,
                    'id' => $this->hash($explorerNode->id)
                ]);
            }
        }
        catch (\Exception $e)
        {
            if(!$e instanceof \Elasticsearch\Common\Exceptions\Missing404Exception){
                Log::alert('ELASTIC-'. $e->getMessage() . '-' . $e->getFile() . '-' . $e->getLine());
            }
        }
    }

    /**
     * @param $node
     * @description Re-Index the explorer node
     */
    public function reIndex($node)
    {
        $this->removeExplorerNode($node);

        $this->seedExplorerNode($node, true);
    }

    /**
     * @param array $nodes
     */
    public function makeExplorerNodeAndReIndex($nodes)
    {
        foreach($nodes as $node)
        {
            $explorerNode = $this->generateNode($node);

            $this->reIndex($explorerNode);
        }
    }

    /**
     * @param $nodeIdentity
     * @param bool $nestedUpdate
     * @description updates the root node based on the identity provided.
     */
    public function updateRootNode($nodeIdentity, $nestedUpdate = true)
    {
        //$rootNode = Folder::visibleTo($this->user)->where('internal_name', $nodeIdentity)->first();
        $rootNode = Folder::where('user_id', Config::get('user_id'))->where('customer_id', Config::get('customer_id'))->where('internal_name', $nodeIdentity)->first();

        if(!is_null($rootNode))
        {
            $explorerNode = Explorernode::createExplorernode($rootNode);

            if(!is_null($explorerNode))
            {
                $nodeChildren = $explorerNode->children();

                if($nestedUpdate && count($nodeChildren))
                {
                    //$latestChildExplorerNode = end($nodeChildren);

                    //$expNode = Explorernode::findById($latestChildExplorerNode['internal_name'], $this->user);

                    $expNode = end($nodeChildren);

                    if(!is_null($expNode))
                    {
                        $this->performUpdate($expNode, true);

                        $this->performNestedUpdates($expNode);

                        if(count($this->unique_explorer_nodes))
                        {
                            $this->seedUniqueNodes();
                        }

                    } else {

                        $this->performUpdate($explorerNode,false);
                    }

                } else {

                    $this->performUpdate($explorerNode,false);
                }
            }
        }
    }

    /**
     * @param $rootExplorerNode
     * @param $child
     * @description updates provided node and its children nested
     */
    public function updateRootNodeAndChildNested($rootExplorerNode, $child)
    {
        $this->performUpdate($rootExplorerNode, false);

        $this->performUpdate($child, false);

        $this->performNestedUpdates($child);
    }

    /**
     * @param $node
     * @description updates a single node
     */
    public function updateSingleNode($node)
    {
        $explorerNodeClass =  Explorernode::class;

        if($node instanceof $explorerNodeClass){
            $formattedNode = $this->fetchNode($node);
        } else {
            $explorerNode = Explorernode::createExplorernode($node);
            if(!is_null($explorerNode)){
                $formattedNode = $this->fetchNode($explorerNode);
            }
        }

        if($formattedNode) {
            $this->apply($this->fetchIndexForNode($formattedNode));
        }
    }

    /**
     * @param $node
     * @description updates a single node and its children
     */
    public function updateSingleNodeAndChildNested($node)
    {
        $node = $this->generateNode($node);

        $this->performUpdate($node, false);

        $this->performNestedUpdates($node);
    }

    /**
     * @param $node
     * @description updates the node with the parent
     */
    public function updateParentNestedNodes($node)
    {
        $expNode = Explorernode::createExplorernode($node);

        if(!is_null($expNode))
        {
            $this->performUpdate($expNode, true);

            if(count($this->unique_explorer_nodes))
            {
                $this->seedUniqueNodes();
            }

            $this->performNestedUpdates($expNode);
        }
    }

    /**
     * @param $node
     * @param $updateParent
     * @description updates multiple nodes at once
     */
    public function updateNodes($node, $updateParent = true)
    {
        if(is_array($node))
        {
            foreach($node as $singleNode)
            {
                $this->performUpdate($singleNode, $updateParent);
            }

        } else {

            $this->performUpdate($node, $updateParent);
        }

        if(count($this->unique_explorer_nodes))
        {
            $this->seedUniqueNodes();
        }
    }

    /**
     * @param $node
     * @return array
     * @description separate the parent unique nodes in the provided nodes array
     */
    protected function separateUniqueNodes($node)
    {
        $uniqueNodes = [];

        foreach($node as $singleNode)
        {
            $generatedNode = $this->generateNode($singleNode);

            if(!is_null($generatedNode))
            {
                if(array_key_exists($generatedNode->id, $uniqueNodes))
                {
                    continue;
                }
                else
                {
                    $uniqueNodes[$generatedNode->id] = $generatedNode;
                }
            }
        }

        if( count($uniqueNodes))
        {
            return array_values($uniqueNodes);
        }

        return [];
    }

    /**
     * @description seeds the separated unique nodes
     */
    protected function seedUniqueNodes()
    {
        foreach($this->unique_explorer_nodes as $node)
        {
            $formattedNode = $this->fetchNode($node);

            $this->apply($this->fetchIndexForNode($formattedNode));
        }

        $this->unique_explorer_nodes = [];
    }

    /**
     * @param $attribute
     * @return null|string
     * @description provides the datetime format for the given attribute
     */
    protected function fetchDateFormatted($attribute)
    {
        if(is_null($attribute))
        {
            return Carbon::createFromFormat('Y-m-d H:i:s', '1990-01-01 00:00:00')->toIso8601String();
        }

        return Carbon::createFromFormat('Y-m-d H:i:s', $attribute)->toIso8601String();
    }

    /**
     * @param $asset
     * @return mixed
     * @throws \Exception
     * @description provides the assets for the elastic document instance
     */
    protected function fetchAssetForElastic($asset)
    {
        $assetClass = Asset::class;

        if(!$asset instanceof $assetClass)
        {
            throw new \Exception("Please provide the object of this instance $assetClass", 500);
        }

        $assetBody = $asset->toArray();

        $assetBody['id'] = $this->hash($asset->id);

        $assetBody['author'] = json_decode($assetBody['author']);

        $assetBody['type_enumeration_number'] = [];

        $assetBody['content'] = json_decode($asset->content);

        $assetBody['tags'] = (is_array($asset->tags)) ? $asset->tags : json_decode($asset->tags);

        foreach($asset->asset2assettype as $assetType)
        {
            if(!is_null($assetType->asset_type))
            {
                $assetBody['type_enumeration_number'][] = $assetType->asset_type->enumeration_number;
            }
        }

        $dateFields = ['validTo', 'validFrom', 'touched_at', 'deleted_at', 'updated_at', 'created_at'];

        foreach($dateFields as $dateField){
            if(array_key_exists($dateField, $assetBody)){
                $assetBody[$dateField] = $this->fetchDateFormatted($asset->$dateField);
            }
        }

        if(isset($assetBody['assettypes']))
        {
            unset($assetBody['assettypes']);
        }

        if(isset($assetBody['asset2assettype']))
        {
            unset($assetBody['asset2assettype']);
        }

        //Log::info(['$assetBody', $assetBody]);

        return $assetBody;
    }

    /**
     * @param $asset
     * @param bool $onlyIndex
     * @return array
     * @description provides the elastic indexed document for the asset
     */
    protected function fetchAssetElasticSeed($asset, $onlyIndex = false)
    {
        $parameter = [
            'index' => static::ELASTIC_INDEX,
            'type' => static::ELASTIC_INDEX_TYPE_ASSETS,
            'id' => $this->hash($asset->id),
        ];

        if($onlyIndex == false)
        {
            $parameter['body'] = $this->fetchAssetForElastic($asset);
        }

        return $parameter;
    }

    /**
     * @param $id
     * @return mixed
     */
    protected function findAsset($id)
    {
        return Asset::with('asset2assettype.asset_type')->where('id', $id)->first();
    }

    /**
     * @param $assetId
     * @description performs the operations for the asset seeding to elastic
     * @return array
     */
    public function seedAsset($assetId)
    {
        $asset = $this->findAsset($assetId);

        if(!is_null($asset))
        {
            $elasticIndex = $this->fetchAssetElasticSeed($asset);

            return $this->apply($elasticIndex);
        }
    }

    /**
     * @param $assetId
     * @description removes the asset document from the elastic
     */
    public function removeAsset($assetId)
    {
        try{
            $asset = $this->findAsset($assetId);

            if($asset)
            {
                $elasticIndex = $this->fetchAssetElasticSeed($asset, $onlyIndex = true);

                $this->client->delete($elasticIndex);
            }

        } catch (\Exception $e) {

            if(!$e instanceof \Elasticsearch\Common\Exceptions\Missing404Exception){
                Log::alert('ELASTIC-'. $e->getMessage() . '-' . $e->getFile() . '-' . $e->getLine());
            }
        }
    }
}