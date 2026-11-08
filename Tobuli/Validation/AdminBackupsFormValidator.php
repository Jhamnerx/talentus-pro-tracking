<?php namespace Tobuli\Validation;

use Illuminate\Validation\Factory as IlluminateValidator;

class AdminBackupsFormValidator extends Validator {

    /**
     * @var array Validation rules for the test form, they can contain in-built Laravel rules or our custom rules
     */
    public $rules = [
        'update' => [
            'type' => 'required|in:custom,auto',
            'ftp_server' => 'required_if:type,custom',
            'ftp_port' => 'required_if:type,custom|integer',
            'ftp_username' => 'required_if:type,custom',
            'ftp_path' => 'required_if:type,custom',
            'period' => 'required',
            'hour' => 'required',
            'drive_remote' => 'required_with:drive_enabled|nullable|regex:/^[A-Za-z0-9_-]+$/',
            'drive_path' => 'required_with:drive_enabled|nullable|regex:/^[A-Za-z0-9_\/-]+$/',
            'retention_daily_days' => 'required_with:drive_enabled|nullable|integer|min:1',
            'retention_long_term_months' => 'required_with:drive_enabled|nullable|integer|min:1|max:12',
            'retention_max_days' => 'required_with:drive_enabled|nullable|integer|min:1',
        ]
    ];

    function __construct( IlluminateValidator $validator ) {
        parent::__construct( $validator );

        $this->rules['update']['ftp_password'] = 'string' . (settings('backups.ftp_password') ? '' : '|required_if:type,custom');
    }

}   //end of class


//EOF