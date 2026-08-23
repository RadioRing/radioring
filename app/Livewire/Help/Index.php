<?php

namespace App\Livewire\Help;

use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Hilfe')]
class Index extends Component
{
    /**
     * Rendert das Handbuch als In-App-Hilfeseite. Das Markdown unter docs/
     * bleibt einzige Quelle – hier wird es nur zu HTML konvertiert.
     */
    public function render()
    {
        // Folgt der eingestellten Sprache und faellt auf die deutsche Fassung zurueck:
        // die ist die fuehrende, die englische kann nachhinken.
        $path = base_path('docs/'.(app()->getLocale() === 'en' ? 'en/handbook.md' : 'de/handbuch.md'));

        if (! is_file($path)) {
            $path = base_path('docs/de/handbuch.md');
        }

        $markdown = is_file($path)
            ? (string) file_get_contents($path)
            : '# Hilfe'."\n\n".'Das Handbuch ist derzeit nicht verfügbar.';

        return view('livewire.help.index', [
            'content' => $this->addHeadingAnchors(Str::markdown($markdown)),
        ])->layout('layouts.app');
    }

    /**
     * Versieht jede Überschrift mit einem GitHub-kompatiblen id-Slug, damit das
     * im Dokument enthaltene Inhaltsverzeichnis innerhalb der Seite springt.
     */
    private function addHeadingAnchors(string $html): string
    {
        return (string) preg_replace_callback(
            '/<(h[1-6])>(.*?)<\/\1>/s',
            function (array $match): string {
                $text = html_entity_decode(strip_tags($match[2]), ENT_QUOTES);
                $slug = strtolower($text);
                $slug = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $slug) ?? '';
                $slug = preg_replace('/\s+/', '-', trim($slug)) ?? '';

                return "<{$match[1]} id=\"{$slug}\">{$match[2]}</{$match[1]}>";
            },
            $html
        );
    }
}
