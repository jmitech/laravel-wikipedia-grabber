<?php

namespace Illuminated\Wikipedia\Grabber\Parser;

use Illuminate\Support\Collection;
use Illuminated\Wikipedia\Grabber\Component\Image;
use Illuminated\Wikipedia\Grabber\Component\Section;
use Illuminated\Wikipedia\Grabber\Wikitext\Templates\DoubleImageTemplate;
use Illuminated\Wikipedia\Grabber\Wikitext\Wikitext;
use Illuminated\Wikipedia\Grabber\Wikitext\WikitextImage;

class SectionsAddImages
{
    protected $sections;
    protected $wikitext;
    protected $mainImage;
    protected $images;
    protected $wikitextSections;

    public function __construct(Collection $sections, array $imagesResponseData = null)
    {
        $this->sections = $sections;

        if (empty($imagesResponseData)) {
            return;
        }

        $this->wikitext = $imagesResponseData['wikitext'];
        $this->mainImage = $imagesResponseData['main_image'];
        $this->images = $this->skipMainImage(
            $this->getUsedImages($this->wikitext, $imagesResponseData['images'])
        );
    }

    public function filter()
    {
        if ($this->noImages()) {
            return $this->sections;
        }

        foreach ($this->sections as $section) {
            if ($section->isMain()) {
                $section->setImages($this->createMainObject());
            }

            $wikitextSection = $this->getWikitextSectionFor($section);
            if (empty($wikitextSection)) {
                continue;
            }

            $sectionImages = $this->getUsedImages($wikitextSection->getBody(), $this->images);
            if (empty($sectionImages)) {
                continue;
            }

            $sectionImages = $this->filterByExtensions($wikitextSection, $sectionImages);
            if (empty($sectionImages)) {
                continue;
            }

            $objects = $this->createObjects($wikitextSection, $sectionImages);
            $section->addImages($objects['images']);
            $section->setGallery($objects['gallery']);

            $this->freeUsedImages($sectionImages);
        }

        return $this->sections;
    }

    protected function noImages()
    {
        return empty($this->mainImage) && empty($this->images);
    }

    protected function skipMainImage(array $images)
    {
        return collect($images)->filter(function (array $image) {
            return !$this->isMainImage($image);
        })->toArray();
    }

    protected function isMainImage(array $image)
    {
        return (array_get($image, 'imageinfo.0.url') == $this->mainImage['original']['source']);
    }

    protected function getUsedImages($wikitext, array $images)
    {
        return collect($images)->filter(function (array $image) use ($wikitext) {
            $file = last(explode(':', $image['title']));
            return str_contains($wikitext, $file);
        })->toArray();
    }

    protected function filterByExtensions($wikitextSection, array $images)
    {
        return collect($images)->filter(function (array $image) use ($wikitextSection) {
            $isJpeg = ends_with($image['title'], ['jpg', 'jpeg']);
            $isSvg = ends_with($image['title'], 'svg');
            return $wikitextSection->isMain() ? $isJpeg : !$isSvg;
        })->toArray();
    }

    protected function createMainObject()
    {
        return collect([
            new Image(
                $this->mainImage['thumbnail']['source'],
                $this->mainImage['thumbnail']['width'],
                $this->mainImage['thumbnail']['height'],
                $this->mainImage['original']['source'],
                'right',
                $this->getMainSection()->getTitle()
            ),
        ]);
    }

    protected function createObjects(Section $wikitextSection, array $images)
    {
        $objects = ['all' => collect(), 'images' => collect(), 'gallery' => collect()];

        foreach ($images as $image) {
            $wikitext = $this->getImageWikitext($wikitextSection, $image);
            $object = $this->createObject($wikitext, $image);

            $collection = $this->isGalleryImage($wikitext) ? $objects['gallery'] : $objects['images'];
            $collection->push($object);

            $objects['all']->push($object);
        }

        $minCount = 4;
        if ($objects['gallery']->count() < $minCount) {
            $objects['gallery'] = collect();
            $objects['images'] = $objects['all'];
        }

        return $objects;
    }

    protected function createObject($imageWikitext, array $image)
    {
        $imageInfo = head($image['imageinfo']);

        $url = $imageInfo['thumburl'];
        $width = $imageInfo['thumbwidth'];
        $height = $imageInfo['thumbheight'];
        $originalUrl = $imageInfo['url'];

        $image = new WikitextImage($imageWikitext);
        $position = $image->getLocation();
        $description = $image->getDescription();

        return new Image($url, $width, $height, $originalUrl, $position, $description);
    }

    protected function getImageWikitext(Section $wikitextSection, array $image)
    {
        $title = $image['title'];
        $file = last(explode(':', $title));

        $line = $this->getImageWikitextLine($wikitextSection->getBody(), $title, $file);

        $openTag = "[[{$title}";
        if (!str_contains($line, $openTag)) {
            if ($this->isMultipleImageLine($line)) {
                return $this->transformMultipleImageLine($line);
            }

            if ($this->isDoubleImageTemplate($line)) {
                return (new DoubleImageTemplate($line))->extract($file);
            }

            return $line;
        }

        $placeholder = '/!! IWG_TITLE !!/';
        $line = str_replace_first($openTag, $placeholder, $line);
        $line = (new Wikitext($line))->plain();
        $line = str_replace_first($placeholder, $openTag, $line);

        $title = preg_quote($title);
        if (preg_match("/\[\[{$title}.*?\]\]/", $line, $matches)) {
            $wikitext = head($matches);

            if ($this->isGrayTable($line)) {
                $wikitext = $this->forceGalleryDisplaying($wikitext);
            }

            return $wikitext;
        }

        return $line;
    }

    protected function getImageWikitextLine($wikitext, $title, $file)
    {
        $lines = collect(preg_split("/\r\n|\n|\r/", $wikitext));

        $line = $lines->first(function ($line) use ($title) {
            return str_contains($line, $title);
        });

        if (!empty($line)) {
            return $line;
        }

        return $lines->first(function ($line) use ($file) {
            return str_contains($line, $file);
        });
    }

    protected function isGalleryImage($imageWikitext)
    {
        return !(starts_with($imageWikitext, '[[') && ends_with($imageWikitext, ']]'));
    }

    protected function forceGalleryDisplaying($imageWikitext)
    {
        $imageWikitext = str_replace_first('[[', '', $imageWikitext);
        $imageWikitext = str_replace_last(']]', '', $imageWikitext);

        return $imageWikitext;
    }

    protected function isDoubleImageTemplate($imageWikitext)
    {
        $imageWikitext = mb_strtolower($imageWikitext, 'utf-8');

        $templates = collect(['double image', 'сдвоенное изображение'])->map(function ($template) {
            return "{{{$template}";
        })->toArray();

        return starts_with($imageWikitext, $templates) && ends_with($imageWikitext, '}}');
    }

    /**
     * @see https://en.wikipedia.org/wiki/Template:Multiple_image - captionN
     * @see https://ru.wikipedia.org/wiki/Шаблон:Фотоколонка+ - текстN
     * @see https://ru.wikipedia.org/wiki/Шаблон:Кратное_изображение - подписьN
     */
    protected function isMultipleImageLine($imageWikitext)
    {
        $imageWikitext = mb_strtolower($imageWikitext, 'utf-8');

        $params = ['caption', 'текст', 'подпись'];
        foreach ($params as $param) {
            if (preg_match_all("/{$param}\d+=/", $imageWikitext) == 1) {
                return true;
            }
        }

        return false;
    }

    protected function transformMultipleImageLine($line)
    {
        $line = preg_replace('/\d+=/', '=', $line);
        $line = (new Wikitext($line))->plain();
        return rtrim($line, '}');
    }

    protected function isGrayTable($imageWikitext)
    {
        return preg_match('/(\s*\|\s*)width=/', $imageWikitext) || preg_match('/(\s*\|\s*)align=/', $imageWikitext);
    }

    protected function freeUsedImages(array $usedImages)
    {
        $usedImages = array_pluck($usedImages, 'title');
        $this->images = collect($this->images)->filter(function (array $image) use ($usedImages) {
            return !in_array($image['title'], $usedImages);
        })->toArray();
    }

    protected function getWikitextSectionFor(Section $section)
    {
        $wikitextSections = $this->getWikitextSections();

        return $wikitextSections->first(function (Section $wikiSection) use ($section) {
            return ($wikiSection->getTitle() == $section->getTitle())
                && ($wikiSection->getLevel() == $section->getLevel());
        });
    }

    protected function getWikitextSections()
    {
        if (empty($this->wikitextSections)) {
            $parser = new SectionsParser($this->getMainSection()->getTitle(), $this->wikitext);
            $this->wikitextSections = $parser->sections();
        }

        return $this->wikitextSections;
    }

    protected function getMainSection()
    {
        return $this->sections->first->isMain();
    }
}
