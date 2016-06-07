<?php

namespace Kontrolio;

use Kontrolio\Rules\Core\Sometimes;
use OutOfBoundsException;
use ReflectionClass;
use Kontrolio\Rules\CallableRuleWrapper;
use Kontrolio\Rules\RuleInterface;
use UnexpectedValueException;

/**
 * Main class of the validation service.
 *
 * @package Kontrolio
 */
class Validator implements ValidatorInterface
{
    /**
     * List of all available validation rules.
     *
     * @var array
     */
    protected static $availableRules = [];

    /**
     * Parsed and cached rules.
     *
     * @var array
     */
    protected static $rulesCache = [];
    
    /**
     * Data to validate.
     *
     * @var array
     */
    protected $data = [];
    
    /**
     * Validation rules.
     *
     * @var array
     */
    protected $rules = [];

    /**
     * Validation messages.
     *
     * @var array
     */
    protected $messages = [];

    /**
     * Validation errors.
     *
     * @var array
     */
    protected $errors = [];

    /**
     * Flag indicating that the validation should stop.
     *
     * @var bool
     */
    protected $shouldStop = false;

    /**
     * Flag indicating that the validation can be bypassed.
     *
     * @var bool
     */
    private $bypass = false;

    /**
     * Validator constructor.
     *
     * @param array $data
     * @param array $rules
     * @param array $messages
     */
    public function __construct(array $data, array $rules, array $messages = [])
    {
        $this->data = $data;
        $this->messages = $messages;
        static::extendAvailableRules();
        $this->setRules($rules);
    }

    /**
     * Extends available rules array.
     *
     * @param array $rules
     *
     * @return $this
     * @throws UnexpectedValueException
     */
    public static function extendAvailableRules(array $rules = [])
    {
        $defaults = (array) require_once __DIR__ . '/../config/aliases.php';

        $keys = array_map(function($key) use ($rules) {
            if (is_int($key)) {
                return static::getRuleInstance($rules[$key])->getName();
            }

            return $key;
        }, array_keys($rules));

        $mapped = array_combine($keys, array_values($rules));

        static::$availableRules = array_merge($defaults, static::$availableRules, $mapped);
    }

    /**
     * Returns new instance of a rule by the given class name.
     *
     * @param string $class
     *
     * @return RuleInterface
     * @throws UnexpectedValueException
     */
    private static function getRuleInstance($class)
    {
        $class = (new ReflectionClass($class));

        if (!$class->isInstantiable()) {
            throw new UnexpectedValueException('Rule class must be instantiable.');
        }

        if (!$class->implementsInterface(RuleInterface::class)) {
            throw new UnexpectedValueException(sprintf('Rule must implement %s.', RuleInterface::class));
        }

        return $class->newInstance();
    }

    /**
     * Returns data that's being validated.
     * 
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Returns validation rules.
     *
     * @return array
     */
    public function getRules()
    {
        return $this->rules;
    }

    /**
     * Checks proper format of the validation rules and sets them for the validator.
     *
     * @param array $rules
     *
     * @return $this
     * @throws OutOfBoundsException
     * @throws UnexpectedValueException
     */
    public function setRules(array $rules)
    {
        $this->rules = $this->formatRules($rules);

        return $rules;
    }

    /**
     * Format rules and check proper types.
     *
     * @param array $all
     * @return array
     * @throws UnexpectedValueException
     */
    private function formatRules(array $all)
    {
        $attributes = array_keys($all);
        $rules = array_map(function($attribute) use ($all) {
            $resolved = $this->resolveRules($all[$attribute]);

            if (!is_array($resolved)) {
                return [$resolved];
            }

            return $resolved;
        }, $attributes);

        return array_combine($attributes, $rules);
    }

    /**
     * Resolves rules from different formats.
     *
     * @param mixed $rules
     *
     * @return array
     */
    private function resolveRules($rules)
    {
        if (is_array($rules)) {
            foreach ($rules as $rule) {
                static::checkRuleType($rule);
            }

            return $rules;
        }

        if (is_string($rules)) {
            return $this->parseRulesFromString($rules);
        }

        static::checkRuleType($rules);

        return $rules;
    }

    /**
     * Parses string to an array of rules.
     *
     * @param string $string
     * @return array
     * @throws UnexpectedValueException
     */
    protected function parseRulesFromString($string)
    {
        if (isset(static::$rulesCache[$string])) {
            return static::$rulesCache[$string];
        }

        $set = explode('|', $string);
        $rules = [];

        foreach ($set as $rule) {
            list($name, $arguments) = $this->getNameAndArgumentsFromString($rule);

            $rules[] = $this->buildRule($name, $arguments);
        }

        return static::$rulesCache[$string] = $rules;
    }

    /**
     * Takes rule identifier and arguments from the string.
     *
     * @param string $rule
     *
     * @return array
     */
    private function getNameAndArgumentsFromString($rule)
    {
        $delimpos = strpos($rule, ':');

        if ($delimpos) {
            $name = substr($rule, 0, $delimpos);
            $arguments = (array) explode(',', substr($rule, $delimpos+1));
        } else {
            $name = $rule;
            $arguments = [];
        }

        return [$name, $arguments];
    }

    /**
     * Builds a new rule object from the given identifier and constructor arguments.
     *
     * @param string $name
     * @param array $arguments
     *
     * @return object
     */
    private function buildRule($name, array $arguments = [])
    {
        $class = $this->getRuleClassName($name);

        return (new ReflectionClass($class))->newInstanceArgs($arguments);
    }

    /**
     * Resolves a rule class name by the identifier.
     *
     * @param string $identifier
     *
     * @return string
     */
    private function getRuleClassName($identifier)
    {
        if (array_key_exists($identifier, static::$availableRules)) {
            return static::$availableRules[$identifier];
        }

        throw new UnexpectedValueException(
            sprintf(
                'Rule identified by `%s` could not be loaded. It must be present in `Validator::$availableRules` array.',
                $identifier
            )
        );
    }

    /**
     * Check rule for the proper type.
     *
     * @param mixed $rule
     * @throws UnexpectedValueException
     */
    private static function checkRuleType($rule)
    {
        if (!$rule instanceof RuleInterface && !is_callable($rule)) {
            throw new UnexpectedValueException(sprintf('Rule must implement `%s` or be callable.', RuleInterface::class));
        }
    }

    /**
     * Returns validation messages.
     *
     * @return array
     */
    public function getMessages()
    {
        return $this->messages;
    }

    /**
     * Validates given data.
     *
     * @return bool
     * @throws UnexpectedValueException
     */
    public function validate()
    {
        foreach ($this->rules as $attribute => $rules) {
            $this->bypass = false;

            foreach ($rules as $rule) {
                $this->handle($attribute, $rule);

                if ($this->bypass) {
                    continue;
                }
                
                if ($this->shouldStopOnFailure($attribute)) {
                    return false;
                }
            }
        }
        
        return !count($this->errors);
    }

    /**
     * Processes a data attribute and creates new validation error message if the validation failed.
     *
     * @param string $attribute
     * @param mixed $rule
     * @throws UnexpectedValueException
     */
    protected function handle($attribute, $rule)
    {
        $value = $this->getValue($attribute);
        $rule = $this->resolveRule($rule, $value);

        if ($rule instanceof Sometimes && $this->valueIsEmpty($value)) {
            $this->bypass = true;

            return;
        }

        if ($rule->canSkipValidation($value) ||
            $rule->emptyValueAllowed() ||
            $rule->isValid($value)) {
            return;
        }

        $this->addError($attribute, $rule);
    }

    /**
     * Returns value of an attribute.
     *
     * @param string $attribute
     *
     * @return mixed
     */
    protected function getValue($attribute)
    {
        return isset($this->data[$attribute]) ? $this->data[$attribute] : null;
    }

    /**
     * Checks whether value is empty.
     *
     * @param mixed $value
     *
     * @return bool
     */
    protected function valueIsEmpty($value)
    {
        return $value === null || $value === '';
    }

    /**
     * Resolves validation rule according to actual rule type.
     *
     * @param callable|RuleInterface $rule
     * @param mixed $value
     *
     * @return RuleInterface
     * @throws UnexpectedValueException
     */
    protected function resolveRule($rule, $value)
    {
        if ($rule instanceof RuleInterface) {
            return $rule;
        }
        
        if (is_callable($rule)) {
            return new CallableRuleWrapper($rule($value));
        }

        throw new UnexpectedValueException(sprintf('Rule must implement `%s` or be callable.', RuleInterface::class));
    }

    /**
     * Creates new validation error message.
     * 
     * @param string $attribute
     * @param RuleInterface $rule
     */
    protected function addError($attribute, RuleInterface $rule)
    {
        $key = $this->prepareRuleKey($attribute, $rule);
        $errors = $this->prepareErrors($attribute, $key, $rule);

        if (isset($this->errors[$attribute])) {
            $this->errors[$attribute] = array_merge($this->errors[$attribute], $errors);
        } else {
            $this->errors[$attribute] = $errors;
        }
    }

    /**
     * Prepares rule key for the validation error messages array.
     *
     * @param string $attribute
     * @param RuleInterface $rule
     *
     * @return string
     */
    protected function prepareRuleKey($attribute, RuleInterface $rule)
    {
        foreach (static::$availableRules as $identifier => $availableRule) {
            if ($availableRule === $rule) {
                return $identifier;
            }
        }

        $name = $rule->getName();

        if ($name) {
            return $name;
        }

        if (isset($this->errors[$attribute])) {
            return count($this->errors[$attribute]);
        }

        return 0;
    }

    /**
     * Prepares validation error messages to proper format.
     *
     * @param string $attribute
     * @param string $ruleName
     * @param RuleInterface $rule
     *
     * @return array
     */
    protected function prepareErrors($attribute, $ruleName, RuleInterface $rule)
    {
        $prefix = $attribute . '.' . $ruleName;
        $messages = $this->getMessagesByAttributeAndRuleName($attribute, $ruleName);
        $violations = $rule->getViolations();

        if (!count($violations)) {
            return [reset($messages)];
        }

        return $this->getMessagesForViolations($messages, $violations, $prefix);
    }

    /**
     * Filter all given messages by the attribute and the rule name.
     *
     * @param string $attribute
     * @param string $ruleName
     *
     * @return array
     */
    private function getMessagesByAttributeAndRuleName($attribute, $ruleName)
    {
        $prefix = $attribute . '.' . $ruleName;
        $messages = array_filter($this->messages, function($key) use ($attribute, $prefix) {
            return $key === $attribute || strpos($key, $prefix) === 0;
        }, ARRAY_FILTER_USE_KEY);

        return $messages;
    }

    /**
     * Returns messages for all validation rule violations.
     *
     * @param array $messages
     * @param array $violations
     * @param string $prefix
     *
     * @return array
     */
    private function getMessagesForViolations(array $messages, array $violations, $prefix)
    {
        $result = [];

        foreach ($violations as $violation) {
            $keys = [
                $prefix,
                $prefix . '.' . $violation
            ];

            foreach ($keys as $key) {
                if (array_key_exists($key, $messages)) {
                    $result[] = $messages[$key];
                }
            }
        }

        return $result;
    }

    /**
     * Determines whether validation should stop on the first failure.
     *
     * @param string $attribute
     *
     * @return bool
     */
    protected function shouldStopOnFailure($attribute)
    {
        return $this->shouldStop &&
               array_key_exists($attribute, $this->errors);
    }

    /**
     * Checks whether there are validation errors.
     *
     * @return bool
     */
    public function hasErrors()
    {
        return count($this->errors) > 0;
    }

    /**
     * Returns all validation errors.
     *
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Determines whether validation should stop on the first failure.
     *
     * @param bool $stop
     *
     * @return $this
     */
    public function shouldStopOnFirstFailure($stop = true)
    {
        $this->shouldStop = $stop;

        return $this;
    }
}