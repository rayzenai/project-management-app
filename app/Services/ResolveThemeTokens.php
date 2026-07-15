<?php

namespace App\Services;

class ResolveThemeTokens
{
    /**
     * @param  array<string, string|null>|null  $fontOverride
     * @return array<string, mixed>
     */
    public function resolved(string $theme, ?array $fontOverride): array
    {
        /** @var array<string, array<string, mixed>> $themes */
        $themes = config('themes.themes');

        if ($theme === 'system') {
            return [
                'light' => $this->applyFonts($themes['light'], $fontOverride),
                'dark' => $this->applyFonts($themes['dark'], $fontOverride),
            ];
        }

        return $this->applyFonts($themes[$theme] ?? $themes['light'], $fontOverride);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, string|null>|null  $fontOverride
     * @return array<string, mixed>
     */
    private function applyFonts(array $definition, ?array $fontOverride): array
    {
        foreach (['display', 'body', 'mono'] as $role) {
            if (! empty($fontOverride[$role])) {
                $definition['tokens']['font'][$role] = $fontOverride[$role];
            }
        }

        return $definition['tokens'];
    }
}
