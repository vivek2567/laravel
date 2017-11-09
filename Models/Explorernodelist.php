<?php

namespace App;

use App\AppModel;

class Explorernodelist extends AppModel
{
    protected $table = "explorernodelist";

    protected $guarded = ['leaf_type', 'content_type', 'content_id', 'display_order', 'use_in_creator', 'name', 'thumbUrl', 'description', 'user_id', 'customer_id', 'created_at', 'updated_at'];

    protected $hidden = [
        'deleted_at'
    ];

    // safeAttributes list
    protected $safeAttributes = [
        ['name', 'description']
    ];

    protected $validationRules = [
        /*"name" => [
            "required" => "explorernodelist.name.required",
        ],
        "description" => [
            "required" => "explorernodelist.description.required",
        ]*/

    ];

    /*
     * ------------------------
     * RELATIONS BLOCK
     * ------------------------
     */

    /* BELONGS TO */

    public function user()
    {
        return $this->belongsTo('App\User');
    }

}
