<?php

namespace Drupal\digitalia_muni_xml\Plugin\views\style;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Drupal\xmlfeedviews\Plugin\views\style\XmlFeedViews;
use Drupal\taxonomy\Entity\Term;

/**
 * Default style plugin to render an OPML feed.
 *
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "digitaliaxmlfeedviews",
 *   title = @Translation("Digitalia XML Feed Views"),
 *   help = @Translation("Generates an XML feed from a view."),
 *   theme = "xmlfeedviews",
 *   display_types = {"feed"}
 * )
 */
class DigitaliaXmlFeedViews extends XmlFeedViews {

  /**
   * Get XML feed views header.
   *
   * @return string
   *   The string containing the description with the tokens replaced.
   */
  public function getHeader() {
    $header = $this->options['xmlfeedviews_head'];

    // replace tokens
    $issue = $this->tokenizeValue("{{ field_member_of }}", 0);
    $volume = $this->tokenizeValue("{{ field_member_of_1 }}", 0);
    $year = $this->tokenizeValue("{{ field_publication_year }}", 0);
    $date = $this->tokenizeValue("{{ field_publication_date }}", 0);
//    $date = $this->tokenizeValue("{{ published_at_1 }}", 0);
    $issue_id = $this->tokenizeValue("{{ nid_1 }}", 0);
    if (!$issue_id) {
      return $header;
    }

    $published = $this->tokenizeValue("{{ status_1 }}", 0);
    //replace with values
    $header = str_replace("{{ field_member_of }}", $issue, $header);
    $header = str_replace("{{ field_member_of_1 }}", $volume, $header);
    $header = str_replace("{{ field_publication_year }}", $year, $header);
    $header = str_replace("{{ field_publication_date }}", $date, $header);
    $header = str_replace("{{ nid_1 }}", $issue_id, $header);
    $header = str_replace("{{ status_1 }}", $published, $header);

    // get issue
    $issue = \Drupal\node\Entity\Node::load($issue_id);

    // add sections
    $sections = $this->getSections($issue);
    $header = str_replace("{{ sections }}", $sections, $header);

    // add cover
    if (str_contains($header, '<covers>{{ cover }}</covers>')) {
      $cover = $this->getCover($issue);
      $header = str_replace("{{ cover }}", $cover, $header); 
    }
    //dpm(htmlspecialchars($header));
    return $header;
  }

  public function getSerialLanguages($issue) {
    if ($volume_id = $issue->get('field_member_of')->getValue()[0]['target_id']) {
      $volume = \Drupal\node\Entity\Node::load($volume_id);
      if ($serial_id = $volume->get('field_member_of')->getValue()[0]['target_id']) {
        $serial = \Drupal\node\Entity\Node::load($serial_id);
        $handle = $serial->get('field_handle')->getValue()[0]['value'];
        $lang = '';
        if (($csv = fopen("/var/www/html/drupal/web/modules/custom/digitalia_muni_xml/languages.csv", "r")) !== FALSE) {
          while ($line = fgetcsv($csv)) {
            if ($line[0] == $handle) {
              $lang = $line[1];
              break;
            }
          }
          fclose($csv);
          return explode('|', $lang);
        }
      }
    }
    return [];
  }

  public function getCover($issue) {
    $media_array = \Drupal::entityTypeManager()->getStorage('media')->loadByProperties(['field_media_of' => $issue->id(), 'bundle' => 'image']);
    if (empty($media_array)) {
      return '';
    }
    $media = reset($media_array);
    $fid = $media->get('field_media_image')->getValue()[0]['target_id'];
    $file = \Drupal\file\Entity\File::load($fid);
    $filename = $file->getFilename();
    $url = 'http://digilib.phil.muni.cz'.$file->createFileUrl();
    $type = pathinfo($url, PATHINFO_EXTENSION);
    $data = file_get_contents($url);
    if ($data === NULL) {
      return '';
    }
    $result = '';
    // $base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);
    $base64 = base64_encode($data);
    //$base64 = 'base64';
    $encoding = '<embed encoding="base64">'.$base64.'</embed>';
    foreach ($this->getSerialLanguages($issue) as $lang) {
      $name = '<cover_image>'.$filename.'</cover_image>';
      $alt = '<cover_image_alt_text>Cover</cover_image_alt_text>';
      $result = $result.'<cover locale="'.$lang.'">'.$name.$alt.$encoding.'</cover>';
    }
    return $result;
  }

  public function getSections($issue) {
    $articles = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['field_member_of' => $issue->id(), 'status' => 1]);
    $sections = [];
    foreach ($articles as $article) {
      if ($tid = $article->get('field_section')->target_id) {
        $page = $article->get('field_pagination_from')->value;
        if (isset($sections[$tid])) {
          $sections[$tid] = min($sections[$tid], $page);
        } else {
          $sections[$tid] = $page;
        }
      }
    }
    asort($sections);
    $result = '<section ref="Hidden" seq="0" editor_restricted="1" meta_indexed="0" meta_reviewed="0" abstracts_not_required="1" hide_title="1" hide_author="0" abstract_word_count="0">
      <id type="internal" advice="ignore">565</id>
      <abbrev locale="cs_CZ">Hidden</abbrev>
      <abbrev locale="en_US">Hidden</abbrev>
      <title locale="cs_CZ">Skrytá sekce</title>
      <title locale="en_US">Hidden section</title>
    </section>';

    $seq = 1;
    $serial_languages = $this->getSerialLanguages($issue);
    foreach ($sections as $tid => $p) {
      $section = Term::load($tid);
      $name = $this->clean($section->getName());
      $result = $result.'<section ref="'.$tid.'" seq="'.$seq.'"><id type="internal" advice="ignore">'.$tid.'</id>';
      foreach ($serial_languages as $lang) {
        $result = $result.'<abbrev locale="'.$lang.'">'.$name.'</abbrev>';
      }
      foreach ($serial_languages as $lang) {
        $result = $result.'<title locale="'.$lang.'">'.$name.'</title>';
      }
      $result = $result.'</section>';

      $seq += 1;
    }
    return $result;
  }

  public function clean($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
  }
}
