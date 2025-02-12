<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

/**
 * Defines the blueprint for classes that manage optimizer settings, including capabilities to save, restore, enable, and disable features or configurations.
 */
interface OptimizerSettingsInterface
{
    /**
     * Saves the current settings to a persistent storage or configuration file.
     *
     * @return array Returns an array containing the status details of the save operation.
     */
    public function saveCurrentSettings(): array;

    /**
     * Restores previous settings from the provided array.
     *
     * @param array $settings An associative array containing the settings to be restored.
     */
    public function restore(array $settings): void;

    /**
     * Disables all functionalities or features as per the implementation context.
     */
    public function disableAll(): void;

    /**
     * Enables all available features, settings, or components within the system.
     */
    public function enableAll(): void;
}
