<?php

namespace Illuminated\Wikipedia\Grabber\Parser;

use Illuminate\Support\Collection;
use Illuminated\Wikipedia\Grabber\Component\Section;
use Illuminated\Wikipedia\Grabber\Wikitext\Wikitext;

class SectionsAddImages
{
    protected $sections;
    protected $wikitextSections;
    protected $imagesResponseData;

    public function __construct(Collection $sections, array $imagesResponseData = null)
    {
        $this->sections = $sections;
        $this->imagesResponseData = $imagesResponseData;
    }

    public function filter()
    {
        if (empty($this->imagesResponseData)) {
            return $this->sections;
        }

        foreach ($this->sections as $section) {
            $wikitextSection = $this->getWikitextSection($section);
            if (empty($wikitextSection)) {
                continue;
            }

            dd($section, $wikitextSection);
        }

        dd('filter method');

        return true; ///////////////////////////////////////////////////////////////////////////////////////////////////
    }

    protected function getWikitextSection(Section $section)
    {
        $wikitextSections = $this->getWikitextSections();

        return $wikitextSections->first(function (Section $wikiSection) use ($section) {
            return ($wikiSection->getTitle() == $section->getTitle())
                && ($wikiSection->getLevel() == $section->getLevel());
        });
    }

    protected function getWikitextSections()
    {
        if (!empty($this->wikitextSections)) {
            return $this->wikitextSections;
        }

        $title = $this->getMainSection()->getTitle();
        $wikitext = $this->imagesResponseData['wikitext'];
        $this->wikitextSections = (new SectionsParser($title, $wikitext))->sections();
        $this->wikitextSections->each(function (Section $section) {
            $sanitized = (new Wikitext($section->getTitle()))->sanitize();
            $section->setTitle($sanitized);
        });

        return $this->wikitextSections;
    }

    protected function getMainSection()
    {
        return $this->sections->first->isMain();
    }
}
