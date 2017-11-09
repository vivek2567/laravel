<?php
/**
 * Created by PhpStorm.
 * User: Mohit Dhiman
 * Date: 12/30/2016
 * Time: 5:05 PM
 */

namespace App\Services;

use Log;
use App\Exceptions\APIException;
use Elasticsearch\ClientBuilder;
use App\Http\Helpers\HashHelper;
use Config;

class ExplorerNodeElasticQueries extends ElasticQuery
{
    /**
     * @description Specify the query type specific
     */
    const QUERY_SPECIFIC = 1;

    /**
     * @description Specify the query type general
     */
    const QUERY_GENERAL = 2;

    /**
     * @var holds the current user
     */
    protected $user;

    /**
     * @var holds the current query
     */
    protected $query;

    /**
     * @var holds the current query type
     */
    protected $query_type;

    /**
     * @var \Elasticsearch\Client
     * @description holds the instance of the elastic client
     */
    protected $client;

    /**
     * @var
     * @description holds the current request being processed
     */
    protected $request;

    /**
     * @var
     * @description holds the current reference being asked
     */
    protected $reference;

    /**
     * ExplorerNodeElasticQueries constructor.
     * @param $user
     * @param $request
     * @param $reference
     */
    public function __construct($user, $request, $reference)
    {
        parent::__construct();

        $this->user = $user;

        $this->request = $request;

        $this->reference = $reference;
    }

    /**
     * @return array
     * @throws \Exception
     * @description execute the current generated query and rteurn the response
     */
    public function execute()
    {
        if (!$this->checkForConnection()) {
            throw new \Exception('Unable to connect to elastic server');
        }

        $query = $this->buildConditionalQuery();

        $resultedExplorerNodes = $this->parseResult($query);

        return [
            'explorernodes' => $resultedExplorerNodes
        ];
    }

    /**
     * @return array
     * @description builds the query based on the conditions
     */
    protected function buildConditionalQuery()
    {
        switch ($this->reference) {
            case ':photos_assets_create_root':
                return $this->buildGeneralQuery(SeedToElastic::ELASTIC_INDEX_TYPE_PHOTOS_ASSETS);
                break;
            case ':vectors_assets_create_root':
                return $this->buildGeneralQuery(SeedToElastic::ELASTIC_INDEX_TYPE_VECTORS_ASSETS);
                break;
            case ':manage_root':
                return $this->buildAccessQuery(':manage_root');
                break;
            case ':create_root':
                return $this->buildAccessQuery(':create_root');
                break;
            case ':marketing_manage_root':
                return $this->buildAccessQuery(':marketing_manage_root');
                break;
            case ':other_data_manage_root':
                return $this->buildAccessQuery(':other_data_manage_root');
                break;
            default:
                return $this->buildQueryInstance($this->reference);
        }
    }

    /**
     * @param $type
     * @return array
     * @description builds a general query
     */
    protected function buildGeneralQuery($type)
    {
        $this->query_type = static::QUERY_GENERAL;

        return [
            'index' => SeedToElastic::ELASTIC_INDEX,
            'type' => $type,
            'body' => [
                'query' => [
                    'match_all' => new \stdClass()
                ]
            ]
        ];
    }

    /**
     * @param $reference
     * @return array
     * @throws APIException
     * @description builds the access query for the user
     */
    protected function buildAccessQuery($reference)
    {
        return $this->buildQueryInstance($reference);
    }

    /**
     * @param $reference
     * @return bool
     * @description checks for the permissions
     */
    protected function checkPermissions($reference)
    {
        $accessQuery = [
            'index' => SeedToElastic::ELASTIC_INDEX,
            'type' => SeedToElastic::ELASTIC_INDEX_TYPE_AUTHOR,
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            [
                                'match' => [
                                    '_id' => HashHelper::encodeId(Config::get('user_id'), config('app.salt'))
                                ],
                            ],
                            [
                                'match' => [
                                    'access_permissions.read' => true
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $permission = $this->fetchAssociatedPermission($reference);

        if (is_null($permission)) {
            return $this->performCheck($accessQuery);
        }

        $accessQuery['body']['query']['bool']['minimum_should_match'] = 1;
        $accessQuery['body']['query']['bool']['should'] = [
            [
                'match' => [
                    "permissions.{$permission}" => true
                ]
            ],
            [
                'match' => [
                    'is_customer_admin' => true
                ]
            ]
        ];
        //Log::info(['access Query', $accessQuery]);
        return $this->performCheck($accessQuery);
    }

    /**
     * @param $accessQuery
     * @return bool
     * @description performs the checks for the user access
     */
    protected function performCheck($accessQuery)
    {
        $accessResult = $this->client->search($accessQuery);
        return ($accessResult['hits']['total'] == 1) ? true : false;
    }

    /**
     * @param null $reference
     * @return array
     * @description builds a query instance
     */
    protected function buildQueryInstance($reference = null)
    {
        $this->query_type = static::QUERY_GENERAL;

        $search = is_null($reference) ? ':root' : $reference;

        if (is_null($reference)) {
            $search = ':root';
        } else {
            if (strpos($reference, '.') !== false) {
                $search = HashHelper::encodeId($reference, config('app.salt'));
            }
        }

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
                                    'parent_id' => $search
                                ]
                            ],
                            [
                                'match' => [
                                    'parent_internal_name' => $search
                                ]
                            ]
                        ],
                        'must' => [
                            [
                                'match' => [
                                    'author_id' => HashHelper::encodeId(Config::get('user_id'), config('app.salt'))
                                ],
                            ],
                            /*[
                                'match' => [
                                    'name' => $this->request->filter[0]['contains']
                                ],
                            ]*/
                        ]
                    ],
                ],
                /*'sort' => [
                    [
                        'displayorder' => [
                            'order' => 'asc'
                        ]
                    ]
                ]*/
            ]
        ];

        if (isset($this->request->order[0]['order']) && isset($this->request->order[0]['field'])) {
            $query['body']['sort'] = [
                [
                    "{$this->request->order[0]['field']}" => [
                        'order' => $this->request->order[0]['order']
                    ]
                ]
            ];
        } else {
            $query['body']['sort'] = [
                [
                    'displayorder' => [
                        'order' => 'asc'
                    ]
                ]
            ];
        }

        if ($this->request->has('limit') && $this->request->has('offset')) {
            $query['body']['from'] = $this->request->get('offset');
            $query['body']['size'] = $this->request->get('limit');
        } else {
            $query['body']['from'] = 0;
            $query['body']['size'] = 25;
        }

        if ($this->request->has('filter')) {
            $filters = $this->request->get('filter');
            $filterCount = count($filters);
            for ($i = 0; $i < $filterCount; $i++) {
                if (isset($this->request->filter[$i]['contains'])
                    && $this->request->filter[$i]['contains'] != ''
                ) {
                    $query['body']['query']['bool']['must'][] = [
                        'match' => [
                            'name' => $this->request->filter[$i]['contains']
                        ]
                    ];
                }

                if (isset($this->request->filter[$i]['field']) && $this->request->filter[$i]['field'] == 'nodestatus') {
                    $query['body']['query']['bool']['must'][] = [
                        'match' => [
                            'nodestatus' => $this->request->filter[$i]['value']
                        ]
                    ];
                }
                if (isset($this->request->filter[$i]['field']) && $this->request->filter[$i]['field'] == 'industry') {
                    $query['body']['query']['bool']['must'][] = [
                        'match' => [
                            'industry' => $this->request->filter[$i]['value']
                        ]
                    ];
                }


            }
        }

        //Log::info(['query', $query]);

        return $query;
    }

    /**
     * @param $reference
     * @return mixed|null
     * @description returns the permissions associated with the current reference
     */
    protected function fetchAssociatedPermission($reference)
    {
        $permissions = [
            ':manage_root' => 'manage.manage.canDisplayManage',
            ':create_root' => 'create.create.canDisplayCreate',
            ':marketing_manage_root' => 'manage.marketing.canDisplayMarketing',
            ':other_data_manage_root' => 'manage.otherdata.canDisplayOtherData',
        ];

        return isset($permissions[$reference]) ? $permissions[$reference] : null;
    }

    /**
     * @param $query
     * @return \Illuminate\Support\Collection
     * @description formats the result
     */
    protected function parseResult($query)
    {
        $this->result = $this->client->search($query);

        switch ($this->query_type) {
            case static::QUERY_SPECIFIC:
                return collect($this->parseSpecificResult());
                break;
            case static::QUERY_GENERAL:
                return collect($this->parseGeneralResult());
                break;
        }
    }

    /**
     * @param $results
     * @return array
     * @description extracts the results
     */
    protected function mapResults($results)
    {
        return array_map(function ($item) {
            return $item['_source'];
        }, $results);
    }

    protected function mapResultsByOrder($results, $orders)
    {
        $itemsByKey = [];
        $sorted = [];

        foreach ($results as $result) {
            $itemsByKey[$result['_id']] = $result['_source'];
        }

        foreach ($orders as $order) {
            if (array_key_exists($order, $itemsByKey)) {
                $sorted[] = $itemsByKey[$order];
            }
        }

        return $sorted;
    }

    /**
     * @return array
     * @description extracts the specific result
     */
    protected function parseSpecificResult()
    {
        if (count($index = $this->result['hits']['hits'])) {

            if (isset($index[0]) && count($results = $index[0]['inner_hits']['children']['hits']['hits'])) {
                return $this->mapResults($results);
            }

            return [];
        }
        return [];
    }

    /**
     * @return array
     * @description extracts the general query result
     */
    protected function parseGeneralResult()
    {
        if (count($index = $this->result['hits']['hits'])) {
            return $this->mapResults($index);
        }

        return [];
    }

    /**
     * @return array
     * @throws APIException
     * @throws \Exception
     * @description returns the breadcrumb for the current query
     */
    public function fetchBreadcrumb()
    {
        if (!$this->checkForConnection()) {
            throw new APIException('ELASTICSEARCH_SERVER_NOT_RUNNING', 500);
        }

        $query = $this->buildBreadcrumbQuery($this->reference);

        $result = $this->client->search($query);

        if (count($result['hits']['hits'])) {

            $breadcrumb = $result['hits']['hits'][0]['_source']['breadcrumb'];

            return $this->fetchNodesForBreadcrumb($breadcrumb);

        }

        throw new APIException('OBJECT_NOT_FOUND', 404);
    }

    /**
     * @param $reference
     * @return array
     * @description generates the current query
     */
    protected function buildBreadcrumbQuery($reference)
    {
        $queryColumn = 'reference_id';

        if (!is_null($reference)) {
            if (substr($reference, 0, 1) == ':') {
                $queryColumn = 'reference_id';
            } else if (strpos($reference, '.') !== false) {
                $reference = HashHelper::encodeId($reference, config('app.salt'));
                $queryColumn = 'id';
            }
        }

        return [
            'index' => SeedToElastic::ELASTIC_INDEX,
            'type' => SeedToElastic::ELASTIC_INDEX_TYPE_NODE,
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            [
                                'match' => [
                                    'author_id' => HashHelper::encodeId(Config::get('user_id'), config('app.salt'))
                                ],
                            ],
                            [
                                'match' => [
                                    $queryColumn => is_null($reference) ? ':root' : $reference
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    public function fetchNodesForBreadcrumb($breadcrumbs)
    {
        $breadcrumbsOrdered = [];

        $query = [
            'index' => SeedToElastic::ELASTIC_INDEX,
            'type' => SeedToElastic::ELASTIC_INDEX_TYPE_NODE,
            'body' => [
                'query' => [
                    'ids' => [
                        'type' => SeedToElastic::ELASTIC_INDEX_TYPE_NODE,
                        'values' => $breadcrumbs
                    ]
                ]
            ]
        ];

        $result = $this->client->search($query);

        if (count($node = $result['hits']['hits'])) {
            $breadcrumbsOrdered = $this->mapResultsByOrder($node, $breadcrumbs);
        }

        return [
            'breadcrumb' => $breadcrumbsOrdered
        ];
    }

}