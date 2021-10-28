<?php

namespace Drupal\archivesspace\Plugin\migrate\process;

use Drupal\migrate\MigrateSkipProcessException;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;

/**
 * Transforms ArchivesSpace single and multipart notes into TypedNote format.
 *
 * @MigrateProcessPlugin(
 *   id = "archivesspace_notes"
 * )
 */
class ArchivesSpaceNotes extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (empty($value)) {
      throw new MigrateSkipProcessException();
    }
    if (!is_array($value)) {
      throw new MigrateException(sprintf('ArchivesSpace Notes process failed for destination property (%s): input is not an array.', $destination_property));
    }
    $type = (isset($this->configuration['type'])) ? $this->configuration['type'] : FALSE;
    if ($value['publish'] === TRUE && (!$type || $type == $value['type'])) {
      $content = '';
      switch ($value['jsonmodel_type']) {
        case 'note_singlepart':
          foreach ($value['content'] as $line) {
            $content .= $this->cleanup($line);
          }
          break;

        case 'note_multipart':
        case 'note_bioghist':
          foreach ($value['subnotes'] as $subnote) {
            if ($subnote['publish'] === TRUE) {
              $class = 'subnote ' . $subnote['jsonmodel_type'];
              $content .= "<div class=\"$class\">\n";
              // Add subnote label.
              if (!empty($subnote['label'])) {
                $content .= '<div class=\"subnote_label\">' . $subnote['label'] . "</div>\n";
              }
              switch ($subnote['jsonmodel_type']) {
                case 'note_abstract':
                case 'note_citation':
                  // Contents comes first.
                  foreach ($subnote['content'] as $subnote_content) {
                    $content .= $this->cleanup($subnote_content);
                  }
                  // Then comes the link (although this will always
                  // be skipped for abstracts).
                  if (!empty($subnote['xlink'])) {
                    $content .= sprintf('<a href="%s">%s</a>', $subnote['xlink']['href'], $subnote['xlink']['title']);
                  }
                  break;

                case 'note_text':
                  $content .= $this->cleanup($subnote['content']);
                  break;

                case 'note_chronology':
                  if (!empty($subnote['items'])) {
                    $content .= "<dl>\n";
                    foreach ($subnote['items'] as $item) {
                      $content .= "<dt>" . $item['event_date'] . "</dt>";
                      foreach ($item['events'] as $event) {
                        $content .= "<dd>$event</dd>\n";
                      }
                    }
                    $content .= "</dl>\n";
                  }
                  break;

                case 'note_orderedlist':
                  if (!empty($subnote['items'])) {
                    $enumeration = (!empty($subnote['enumeration'])) ? $subnote['enumeration'] : '';
                    $content .= "<ol class=\"$enumeration\">\n";
                    foreach ($subnote['items'] as $item) {
                      $content .= "<li>$item</li>";
                    }
                    $content .= "</ol>\n";
                  }
                  break;

                case 'note_definedlist':
                  if (!empty($subnote['items'])) {
                    $content .= "<dl>\n";
                    foreach ($subnote['items'] as $item) {
                      $content .= "<dt>" . $item['label'] . "</dt>";
                      $content .= "<dd>" . $item['value'] . "</dd>";
                    }
                    $content .= "</dl>\n";
                  }
                  break;

                case 'note_outline':
                  // Recursively build out the levels as nested ordered lists.
                  $this->processNoteOutline($subnote['levels'], $content);
                  break;
              }
              $content .= '</div>';
            }
          }
          break;
      }
      if (!empty($content)) {
        return [
          'value' => $content,
          'format' => 'full_html',
        ];
      }
    }
  }

  /**
   * Cleans up EAD-specific markup for Basic HTML compliance.
   */
  protected function cleanup($string) {
    // These tags get stripped:
    // - language (part of langmaterial)
    // - date: possibly change to <time datetime='...'> ?
    // - function
    // - occupation
    // - subject
    // - corpname
    // - persname
    // - famname
    // - name
    // - geogname
    // - genreform
    // - title
    // Consider possible replacement.
    // Tag replacements.
    $patterns = [
      '/<emph>([^<]+)<\/emph>/',
      '/<ref ([^<]+)<\/ref>/',
      // @todo preserve extref show and title attributes.
      '/<extref .*href="(.*?)".*>([^<]+)<\/extref>/',
    ];
    $replacements = [
      '<em>$1</em>',
      '<a $1</a>',
      '<a href="$1">$2</a>',
    ];
    $string = preg_replace($patterns, $replacements, $string);

    // Replace newlines with paragraph tags.
    // @todo prevent blockquotes from getting wrapped in paragraphs
    // but still support paragraphs inside blockquotes.
    $paragraphs = '';
    foreach (explode(PHP_EOL, $string) as $line) {
      $trimmed = trim($line);
      if (!empty($trimmed)) {
        $paragraphs .= '<p>' . $trimmed . '</p>';
      }
    }
    return $paragraphs;
  }

  /**
   * Recusively build an outline sub-note as nested ordered lists.
   */
  protected function processNoteOutline(array $items, &$content) {
    // Wrap items in ordered list.
    $content .= "<ol>\n";
    foreach ($items as $item) {
      $content .= "<li>";
      if (is_array($item)) {
        $this->processNoteOutline($item['items'], $content);
      }
      else {
        $content .= $item;
      }
      $content .= "</li>\n";
    }
    $content .= "</ol>\n";
  }

}
