<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

/**
 * The visual design a report card is printed in. A school picks one
 * (school setting `report_card.template`); legacy had exactly one, hard-wired.
 *
 * Not to be confused with ReportLayout, which is the card's content shape
 * (single exam or term). A template changes how a card looks and never what it
 * says: every template renders the same ReportCardBuilder payload.
 *
 * The value is stored — in school_settings and, frozen at publication, in
 * report_card_snapshots payloads — so a case is never renamed or removed once
 * a school could have issued cards with it.
 */
enum ReportTemplate: string
{
    /** The design ported from legacy report_pdf.css. The default, and what every card issued before templates existed is. */
    case CLASSIC = 'classic';
    case MODERN = 'modern';
    case FORMAL = 'formal';

    public function label(): string
    {
        return match ($this) {
            self::CLASSIC => 'Classic',
            self::MODERN => 'Modern',
            self::FORMAL => 'Formal',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CLASSIC => 'The original Elimu Pass report card: blue header band and shaded mark columns.',
            self::MODERN => 'A clean, open layout with light rules, a pupil summary panel and a bold totals bar.',
            self::FORMAL => 'A compact, fully ruled black-and-white form in a serif typeface; photocopies cleanly.',
        };
    }

    /**
     * The template a card payload asks for. Classic when it names none (cards
     * frozen before templates existed) or one this code does not know (a
     * snapshot must always print, whatever it holds).
     *
     * @param  array<string, mixed>  $card
     */
    public static function fromCard(array $card): self
    {
        $value = $card['template'] ?? null;

        return (is_string($value) ? self::tryFrom($value) : null) ?? self::CLASSIC;
    }

    /** Lenient read of a stored setting: anything unrecognised is Classic. */
    public static function fromSetting(mixed $value): self
    {
        return (is_string($value) ? self::tryFrom($value) : null) ?? self::CLASSIC;
    }

    /** The Blade view that prints one card in this template. */
    public function view(): string
    {
        return "reports.templates.{$this->value}.card";
    }

    /** Its print stylesheet — inside the report views, which is dompdf's chroot. */
    public function stylesheetPath(): string
    {
        return resource_path("views/reports/templates/{$this->value}/card.css");
    }

    /**
     * What the settings screen offers.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $t) => ['value' => $t->value, 'label' => $t->label(), 'description' => $t->description()],
            self::cases(),
        );
    }
}
