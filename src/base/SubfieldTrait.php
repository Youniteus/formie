<?php
namespace verbb\formie\base;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;
use craft\validators\HandleValidator;
use verbb\formie\Formie;

trait SubfieldTrait
{
    // Public Properties
    // =========================================================================

    public $subfieldLabelPosition;


    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public function hasSubfields(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getElementValidationRules(): array
    {
        $rules = parent::getElementValidationRules();
        $rules[] = [$this->handle, 'validateRequiredFields', 'skipOnEmpty' => false];

        return $rules;
    }

    /**
     * @inheritDoc
     */
    public function validateRequiredFields(ElementInterface $element)
    {
        $value = $element->getFieldValue($this->handle);
        $subFields = ArrayHelper::getColumn($this->getSubfieldOptions(), 'handle');

        foreach ($subFields as $subField) {
            $labelProp = "{$subField}Label";
            $enabledProp = "{$subField}Enabled";
            $requiredProp = "{$subField}Required";
            $fieldValue = $value->$subField ?? '';

            if ($this->$enabledProp && ($this->required || $this->$requiredProp) && StringHelper::isBlank($fieldValue)) {
                $element->addError(
                    $this->handle,
                    Craft::t('formie', '"{label}" cannot be blank.', [
                        'label' => $this->$labelProp
                    ])
                );
            }
        }
    }
}
