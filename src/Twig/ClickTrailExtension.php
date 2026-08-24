<?php

declare(strict_types=1);

namespace ClickTrail\Symfony\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * RENDER-ONLY CONTRACT (do not violate):
 *
 * - This extension NEVER makes HTTP calls.
 * - It NEVER queries a database or any external service.
 * - It NEVER persists anything (no session, no cookie, no file, no cache).
 * - It NEVER evaluates consent legality; it renders what it is given.
 *
 * All dynamic output is escaped with htmlspecialchars(..., ENT_QUOTES).
 */
final class ClickTrailExtension extends AbstractExtension
{
    /** Canonical hidden-input field order (October AttributionHidden / GTM parity). */
    private const HIDDEN_FIELD_ORDER = [
        'visitor_id',
        'session_id',
        'event_id',
        'site_id',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'utm_id',
        'gclid',
        'gbraid',
        'wbraid',
        'fbclid',
        'msclkid',
        'ttclid',
        'twclid',
        'li_fat_id',
        'sccid',
        'epik',
        'landing_page',
        'initial_referrer',
        'consent_state',
    ];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('clicktrail_head', [$this, 'renderHead'], ['is_safe' => ['html']]),
            new TwigFunction('clicktrail_hidden_attribution_inputs', [$this, 'hiddenAttributionInputs'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Renders the loader script tag plus data-ct-* config attributes from
     * $context. Key "script_src" becomes src; other scalar keys become
     * data-ct-<key> (underscores -> hyphens).
     *
     * @param array<string, mixed> $context
     */
    public function renderHead(array $context): string
    {
        $src = isset($context['script_src']) && is_scalar($context['script_src']) ? (string) $context['script_src'] : '';
        if ($src === '') {
            return ''; // no loader configured: render nothing rather than a broken tag
        }
        unset($context['script_src']);

        $attrs = ' src="' . $this->escape($src) . '"';
        foreach ($context as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            $name = 'data-ct-' . str_replace('_', '-', strtolower($key));
            $attrs .= ' ' . $name . '="' . $this->escape((string) $value) . '"';
        }

        return '<script' . $attrs . ' async></script>';
    }

    /**
     * Renders hidden form inputs for known attribution fields only, in
     * canonical order. Unknown keys and empty values are skipped.
     *
     * @param array<string, mixed> $attribution
     */
    public function hiddenAttributionInputs(array $attribution): string
    {
        $html = '';
        foreach (self::HIDDEN_FIELD_ORDER as $field) {
            $value = $attribution[$field] ?? null;
            if ($value === null || $value === '' || !is_scalar($value)) {
                continue;
            }
            $html .= '<input type="hidden" name="' . $this->escape($field)
                . '" value="' . $this->escape((string) $value) . '">' . "\n";
        }

        return $html;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
