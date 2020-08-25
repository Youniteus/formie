<?php
namespace verbb\formie\integrations\crm;

use verbb\formie\base\Crm;
use verbb\formie\base\Integration;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\errors\IntegrationException;
use verbb\formie\events\SendIntegrationPayloadEvent;
use verbb\formie\models\CrmObject;
use verbb\formie\models\IntegrationField;

use Craft;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\web\View;

class HubSpot extends Crm
{
    // Properties
    // =========================================================================

    public $apiKey;
    public $mapToContact = false;
    public $mapToDeal = false;
    public $mapToCompany = false;
    public $contactFieldMapping;
    public $dealFieldMapping;
    public $companyFieldMapping;


    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public static function displayName(): string
    {
        return Craft::t('formie', 'HubSpot');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return Craft::t('formie', 'Manage your HubSpot customers by providing important information on their conversion on your site.');
    }

    /**
     * @inheritDoc
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['apiKey'], 'required'];

        $contact = $this->getFormSettings()['contact'] ?? [];
        $deal = $this->getFormSettings()['deal'] ?? [];
        $company = $this->getFormSettings()['company'] ?? [];

        // Validate the following when saving form settings
        $rules[] = [['contactFieldMapping'], 'validateFieldMapping', 'params' => $contact, 'when' => function($model) {
            return $model->enabled && $model->mapToContact;
        }, 'on' => [Integration::SCENARIO_FORM]];

        $rules[] = [['dealFieldMapping'], 'validateFieldMapping', 'params' => $deal, 'when' => function($model) {
            return $model->enabled && $model->mapToDeal;
        }, 'on' => [Integration::SCENARIO_FORM]];

        return $rules;
    }

    /**
     * @inheritDoc
     */
    public function fetchFormSettings()
    {
        $settings = [];
        $dealPipelinesOptions = [];
        $dealStageOptions = [];

        try {
            $response = $this->_request('GET', 'crm/v3/pipelines/deals');
            $pipelines = $response['results'] ?? [];

            foreach ($pipelines as $pipeline) {
                $dealPipelinesOptions[] = [
                    'label' => $pipeline['label'],
                    'value' => $pipeline['id'],
                ];

                $stages = $pipeline['stages'] ?? [];

                foreach ($stages as $stage) {
                    $dealStageOptions[] = [
                        'label' => $pipeline['label'] . ': ' . $stage['label'],
                        'value' => $stage['id'],
                    ];
                }
            }

            // Get Contacts fields
            $response = $this->_request('GET', 'crm/v3/properties/contacts');
            $fields = $response['results'] ?? [];

            $contactFields = array_merge([
                new IntegrationField([
                    'handle' => 'email',
                    'name' => Craft::t('formie', 'Email'),
                    'required' => true,
                ]),
            ], $this->_getCustomFields($fields, ['email']), true);

            // Get Companies fields
            $response = $this->_request('GET', 'crm/v3/properties/companies');
            $fields = $response['results'] ?? [];

            $companyFields = array_merge([
                new IntegrationField([
                    'handle' => 'name',
                    'name' => Craft::t('formie', 'Name'),
                    'required' => true,
                ]),
            ], $this->_getCustomFields($fields, ['name']), true);

            // Get Deals fields
            $response = $this->_request('GET', 'crm/v3/properties/deals');
            $fields = $response['results'] ?? [];

            $dealFields = array_merge([
                new IntegrationField([
                    'handle' => 'dealname',
                    'name' => Craft::t('formie', 'Deal Name'),
                    'required' => true,
                ]),
                new IntegrationField([
                    'handle' => 'pipeline',
                    'name' => Craft::t('formie', 'Deal Pipeline'),
                    'required' => true,
                    'options' => [
                        'label' => Craft::t('formie', 'Pipelines'),
                        'options' => $dealPipelinesOptions,
                    ],
                ]),
                new IntegrationField([
                    'handle' => 'dealstage',
                    'name' => Craft::t('formie', 'Deal Stage'),
                    'required' => true,
                    'options' => [
                        'label' => Craft::t('formie', 'Stages'),
                        'options' => $dealStageOptions,
                    ],
                ]),
            ], $this->_getCustomFields($fields, ['dealname', 'pipeline', 'dealstage']), true);

            $settings = [
                'contact' => $contactFields,
                'deal' => $dealFields,
                'company' => $companyFields,
            ];
        } catch (\Throwable $e) {
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]), true);
        }

        return $settings;
    }

    /**
     * @inheritDoc
     */
    public function sendPayload(Submission $submission): bool
    {
        try {
            $contactValues = $this->getFieldMappingValues($submission, $this->contactFieldMapping);
            $dealValues = $this->getFieldMappingValues($submission, $this->dealFieldMapping);
            $companyValues = $this->getFieldMappingValues($submission, $this->companyFieldMapping);

            $contactId = null;

            if ($mapToContact) {
                $email = ArrayHelper::getValue($contactValues, 'email');

                // Prepare the payload for HubSpot, required for v1 API
                $contactPayload = [];

                foreach ($contactValues as $key => $value) {
                    $contactPayload['properties'][] = [
                        'property' => $key,
                        'value' => $value,
                    ];
                }

                // Create or update the contact
                $response = $this->_sendPayload($submission, "contacts/v1/contact/createOrUpdate/email/{$email}", $contactPayload);

                if ($response === false) {
                    return false;
                }

                $contactId = $response['vid'] ?? '';

                if (!$contactId) {
                    Integration::error($this, Craft::t('formie', 'Missing return “contactId” {response}', [
                        'response' => Json::encode($response),
                    ]), true);

                    return false;
                }
            }

            if ($this->mapToDeal) {
                $dealPayload = [];

                if ($contactId) {
                    $dealPayload = [
                        'associations' => [
                            'associatedVids' => [$contactId],
                        ],
                    ];
                }

                foreach ($dealValues as $key => $value) {
                    $dealPayload['properties'][] = [
                        'name' => $key,
                        'value' => $value,
                    ];
                }

                $response = $this->_sendPayload($submission, 'deals/v1/deal', $dealPayload);

                if ($response === false) {
                    return false;
                }

                $dealId = $response['dealId'] ?? '';

                if (!$dealId) {
                    Integration::error($this, Craft::t('formie', 'Missing return “dealId” {response}', [
                        'response' => Json::encode($response),
                    ]), true);

                    return false;
                }
            }
        } catch (\Throwable $e) {
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]), true);

            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function fetchConnection(): bool
    {
        try {
            $response = $this->_request('GET', 'crm/v3/properties/contacts');
        } catch (\Throwable $e) {
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]), true);

            return false;
        }

        return true;
    }


    // Private Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    private function _getClient()
    {
        if ($this->_client) {
            return $this->_client;
        }

        return $this->_client = Craft::createGuzzleClient([
            'base_uri' => 'https://api.hubapi.com/',
            'query' => ['hapikey' => $this->apiKey],
        ]);
    }

    /**
     * @inheritDoc
     */
    private function _request(string $method, string $uri, array $options = [])
    {
        $response = $this->_getClient()->request($method, trim($uri, '/'), $options);

        return Json::decode((string)$response->getBody());
    }

    /**
     * @inheritDoc
     */
    private function _sendPayload($submission, $endpoint, $payload, $method = 'POST')
    {
        // Allow events to cancel sending
        if (!$this->beforeSendPayload($submission, $payload)) {
            return false;
        }

        $response = $this->_request($method, $endpoint, [
            'json' => $payload,
        ]);

        // Allow events to say the response is invalid
        if (!$this->afterSendPayload($submission, $payload, $response)) {
            return false;
        }

        return $response;
    }

    /**
     * @inheritDoc
     */
    private function _getCustomFields($fields, $excludeNames = [])
    {
        $customFields = [];

        $supportedFields = [
            'string',
            'enumeration',
            'datetime',
            'date',
            'phone_number',
            'bool',
            'number',
        ];

        foreach ($fields as $key => $field) {
            if ($field['modificationMetadata']['readOnlyValue'] || $field['hidden'] || $field['calculated']) {
                continue;
            }

            // Only allow supported types
            if (!in_array($field['type'], $supportedFields)) {
                 continue;
            }

            // Exclude any names
            if (in_array($field['name'], $excludeNames)) {
                 continue;
            }

            $customFields[] = new IntegrationField([
                'handle' => $field['name'],
                'name' => $field['label'],
                'type' => $field['type'],
            ]);
        }

        return $customFields;
    }
}