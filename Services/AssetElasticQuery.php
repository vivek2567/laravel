<?php
/**
 * Created by PhpStorm.
 * User: Mohit Dhiman
 * Date: 3/3/2017
 * Time: 3:21 PM
 */

namespace App\Services;

use App\User;
use Illuminate\Http\Request;
use App\Http\Helpers\HashHelper;

class AssetElasticQuery extends ElasticQuery
{
    /**
     * @var
     */
    protected $user;

    /**
     * @var
     */
    protected $request;

    /**
     * AssetElasticQuery constructor.
     * @param $user
     * @param $request
     */
    public function __construct($user, Request $request)
    {
        parent::__construct();

        $this->user = $user;

        $this->request = $request;
    }

    /**
     * @param $user
     */
    public function setUser($user)
    {
        $this->user = $user;
    }

    /**
     * @param $request
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;
    }

    /**
     * @return array
     * @description: Creates a basic query needed for the assets
     */
    protected function fetchBasicQuery()
    {
        $query = [
            'index' => SeedToElastic::ELASTIC_INDEX,
            'type' => SeedToElastic::ELASTIC_INDEX_TYPE_ASSETS,
            'body' => [
                'query' => [
                    //'match_all' => new \stdClass(),
                    'bool' => [
                        //'must' => [
                        //    [
                        //        'term' => [
                        //            'type_enumeration_number' => 6
                        //        ],
                        //    ],
                        //],
                        'must' => [
                            'match' => [
                                'use_in_creator' => 1
                            ],
                            'match' => [
                                'display_increator' => 1
                            ],
                        ],
                        'should' => [
                            [
                                'match' => [
                                    'user_id' => $this->user->id
                                ]
                            ],
                            [
                                'match' => [
                                    'customer_id' => $this->user->id
                                ]
                            ],
                            [
                                'match' => [
                                    'public' => 1
                                ]
                            ]
                        ],
                        'minimum_should_match' => 1
                    ],
                ],
                'from' => static::OFFSET,
                'size' => static::NUMBER_OF_RECORDS,
                'sort' => [
                    'created_at' => 'desc'
                ]
            ]
        ];

        return $query;
    }

    /**
     * @return array
     * @description: apply the request filters
     */
    protected function applyRequestFilters($query)
    {
        if($this->request->has('filter'))
        {
            $filters = $this->request->get('filter');
            $filterCount = count($filters);
            for($i=0;$i<$filterCount;$i++)
            {
                if(isset($filters[$i]))
                {
                    $filter = $filters[$i];
                    if($filter['field'] == 'assettypes')
                    {
                        $query['body']['query']['bool']['must'] = [
                            [
                                'term' => [
                                    'type_enumeration_number' => (int) $filter['value']
                                ],
                            ],[
                                'match' => [
                                    'use_in_creator' => 1
                                ]
                            ],
                            [
                                'match' => [
                                    'display_increator' => 1
                                ]
                            ],
                        ];
                    }
                    if($filter['field'] == 'industries')
                    {
                        $query['body']['query']['bool']['must'] = [
                            [
                                'term' => [
                                    'industries' => $filter['value']
                                ],
                            ],
                        ];

                    }

                }

            }
        }

        return $query;
    }

    /**
     * @param $query
     * @return array
     * @description: apply pagination and sorting
     */

    protected function applyPaginationAndSorting($query)
    {
        if($this->request->has('limit') && $this->request->has('offset')){
            $query['body']['from'] =  $this->request->get('offset') * $this->request->get('limit');
            $query['body']['size'] = $this->request->get('limit');
        }

        if(isset($this->request->order[0]['order']) && isset($this->request->order[0]['field'])){
            $query['body']['sort'] =  [
                $this->request->order[0]['field'] => $this->request->order[0]['order']
            ];
        }

        return $query;
    }

    /**
     * @return array
     * @description: builds the query needed
     */
    protected function buildQuery()
    {
        $basicQuery = $this->fetchBasicQuery();

        $query = $this->applyRequestFilters($basicQuery);

        return $this->applyPaginationAndSorting($query);
    }

    protected function paginationInstance($query, $result)
    {
        $pagination = new \stdClass();

        $pagination->page = new \stdClass();
        $pagination->page->previous = null;
        $pagination->page->next = null;
        $pagination->page->current = ($query['body']['from'] == 0) ? 1 : $query['body']['from']/$query['body']['size'];
        $pagination->page->max = ceil($result['hits']['total']/$query['body']['size']);

        $pagination->items = new \stdClass();
        $pagination->items->limit = ($this->request->has('limit')) ? $this->request->get('limit') : static::NUMBER_OF_RECORDS;
        $pagination->items->count = $result['hits']['total'];

        return $pagination;
    }

    /**
     * @param $query
     * @return array
     */
    protected function results($query)
    {
        $assets = [];

        $result = $this->client->search($query);

        if(count($records = $result['hits']['hits']))
        {
            $assets = array_map(function($item){
                $record = $item['_source'];
                //$record['content'] = json_decode($record['content']);
                //$record['tags'] = json_decode($record['tags']);
                return $record;
            }, $records);
        }

        $pagination = $this->paginationInstance($query, $result);

        return [
            'assets' => $assets,
            'pagination' => $pagination,
            #'query' => $query
        ];
    }

    /**
     * Executes the query and returns the result
     */
    public function execute()
    {
        if(!$this->checkForConnection())
        {
            throw new \Exception('Unable to connect to elastic server');
        }

        $query = $this->buildQuery();

        return $this->results($query);
    }
}