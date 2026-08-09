<?php

namespace Google\ApiCore;

/**
 * Provides functionality for loading a resource name template map from a descriptor config,
 * retrieving a PathTemplate, and parsing values using registered templates.
 *
 * @internal
 */
trait ResourceHelperTrait
{
    /** @var array|null */
    private static $templateMap;

    /**
     * placeholder for this function like we have in GapicClientTrait
     */
    private static function getClientDefaults()
    {
        return [];
    }

    private static function registerPathTemplates()
    {
        $templateConfigPath = self::getClientDefaults()['descriptorsConfigPath'];
        
        self::loadPathTemplates($templateConfigPath, self::SERVICE_NAME);
    }

    private static function loadPathTemplates(string $configPath, string $serviceName)
    {
        
        if (!is_null(self::$templateMap)) {
            return;
        }

        $descriptors = require($configPath);
        $templates = $descriptors['interfaces'][$serviceName]['templateMap'] ?? [];
        self::$templateMap = [];
        foreach ($templates as $name => $template) {
            self::$templateMap[$name] = new PathTemplate($template);
        }
    }

    private static function getPathTemplate(string $key)
    {
        
        if (is_null(self::$templateMap)) {
            self::registerPathTemplates();
        }
        return self::$templateMap[$key] ?? null;
    }

    private static function parseFormattedName(string $formattedName, ?string $template = null): array
    {
        if (is_null(self::$templateMap)) {
            self::registerPathTemplates();
        }
        if ($template) {
            if (!isset(self::$templateMap[$template])) {
                throw new ValidationException("Template name $template does not exist");
            }

            return self::$templateMap[$template]->match($formattedName);
        }

        foreach (self::$templateMap as $templateName => $pathTemplate) {
            try {
                return $pathTemplate->match($formattedName);
            } catch (ValidationException $ex) {
                
            }
        }

        throw new ValidationException("Input did not match any known format. Input: $formattedName");
    }
}
