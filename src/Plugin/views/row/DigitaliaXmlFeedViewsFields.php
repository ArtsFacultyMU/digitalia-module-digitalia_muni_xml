<?php

namespace Drupal\digitalia_muni_xml\Plugin\views\row;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\row\RowPluginBase;
use Drupal\Core\Url;
use Drupal\xmlfeedviews\Plugin\views\row\XmlFeedViewsFields;


/**
 * Renders an XML FEED VIEWS item based on fields.
 *
 * @ViewsRow(
 *   id = "digitaliaxmlfeedviews_fields",
 *   title = @Translation("Digitalia XML Feed Views fields"),
 *   help = @Translation("Display fields as XML items."),
 *   theme = "xmlfeedviews_row",
 *   display_types = {"feed"}
 * )
 */
class DigitaliaXmlFeedViewsFields extends XmlFeedViewsFields {

  /**
   * Render.
   *
   * @param object $row
   *   Row object.
   *
   * @return array|string
   *   Returns array or string.
   */
  public function render($row) {
    static $row_index;
    if (!isset($row_index)) {
      $row_index = 0;
    }

    $item = new \stdClass();
    $item->body_before = isset($this->options['xmlfeedviews_body_before']) ? $this->options['xmlfeedviews_body_before'] : NULL;
    $item->body = $this->getBodyField($row_index, $this->options['xmlfeedviews_body']);
    $item->body_after = isset($this->options['xmlfeedviews_body_after']) ? $this->options['xmlfeedviews_body_after'] : NULL;

    $row_index++;

    $build = [
      '#theme' => $this->themeFunctions(),
      '#view' => $this->view,
      '#options' => $this->options,
      '#row' => $item,
      '#field_alias' => isset($this->field_alias) ? $this->field_alias : '',
    ];

    $body = $item->body;
    $article_id = trim(explode('</id>', explode('<id type="internal" advice="ignore">', $body)[1])[0]);
    $article = \Drupal\node\Entity\Node::load($article_id);
 
    // version
    if (str_contains($body, '###version###') and str_contains($body, '###advice###')) {
      $advice = 'ignore';
      $version = $article->get('field_ojs_version')->getValue()[0]['value'];
      if (empty($version)) {
        $version = 1;
      }
      $ojs_source = $article->get('field_ojs_source')->getValue();
      if (!empty($ojs_source) and $ojs_source[0]['value']) {
        $advice = 'update';
        $version = max(2, $version);
      }

      $body = str_replace('###version###', $version, $body);
      $body = str_replace('###advice###', $advice, $body);

    } 

    $serial_languages = $this->getSerialLanguages($article);
    $locales = $this->getLocales();

    $titles = $this->getTitles($article, $locales, $serial_languages);
    $body = str_replace('###titles###', $titles, $body);

    $abstracts = $this->getAbstracts($article, $locales, $serial_languages);
    $body = str_replace('###abstracts###', $abstracts, $body);

    $keywords = $this->getKeywords($article, $locales, $serial_languages);
    $body = str_replace('###keywords###', $keywords, $body);

    $authors = $this->getAuthors($article, $serial_languages);
    $body = str_replace('###authors###', $authors, $body);

    $section_field = $article->get('field_section')->getValue();
    if (!empty($section_field) and $section_id=$section_field[0]['target_id']) {
      $section = \Drupal\taxonomy\Entity\Term::load($section_id)->getName(); 
      $body = str_replace('###section###', $this->clean($section), $body);
    } else {
      $body = str_replace('###section###', 'Hidden', $body);
    }
    $body = str_replace('###counter###', $row_index, $body);

    $pdf = $this->getPdf($article);
    $pdf_locale = $this->getLocale($article, $locales);
    $body = str_replace('###article_galley###', $pdf, $body);
    $body = str_replace('###pdf_locale###', $pdf_locale, $body);

    $body = str_replace('###license_start###', '<licenseUrl>', $body);
    $body = str_replace('###license_end###', '</licenseUrl>', $body);

    $body = str_replace('###citations_start###', '<citations>', $body);
    $body = str_replace('###citations_end###', '</citations>', $body);
    $body = str_replace('###citation_start###', '<citation>', $body);
    $body = str_replace('###citation_end###', '</citation>', $body);

    $author_id = $this->getAuthorID($article);
    $body = str_replace('###primary_contact###', $author_id, $body);

    //dpm(htmlspecialchars($body));
    $item->body = $body;
    $item->body_before = str_replace('{{ nid }}', $article_id, $item->body_before); 

    return $build;
  }

  public function getLocales() {
    $locales = [];
    if (($handle = fopen("/var/www/html/drupal/web/modules/custom/digitalia_muni_xml/localization.csv", "r")) !== FALSE) {
      while ($line = fgetcsv($handle)) {
        $locales[$line[1]] = $line[2];
      }
      fclose($handle);
      return $locales;
    }
    return [];
  }

  public function getKeywords($article, $locales, $serial_languages) {
    $keywords = $article->get('field_keywords')->getValue();

    if (empty($keywords)) {
      return '';
    }

    $result = [];
    $result_xml = '';

    foreach ($serial_languages as $lang) {
      $result[$lang] = '';
    }

    foreach ($keywords as $kw) {
      $lang = $locales[$kw['rel_type']];
      $tid = $kw['target_id'];
      $term = \Drupal\taxonomy\Entity\Term::load($tid);

      $result[$lang] = $result[$lang].'<keyword>'.$this->clean($term->getName()).'</keyword>';
    }

    foreach ($result as $lang => $value) {
      if ($value != '') {
        $result_xml = $result_xml.'<keywords locale="'.$lang.'">'.$value.'</keywords>'; 
      }
    }
    return $result_xml;
  }

  public function getAbstracts($article, $locales, $serial_languages) {
    $abstracts = $article->get('field_description')->getValue();
    if (empty($abstracts)) {
      return '';
    }

    $result_xml = '';
    $result = [];
    foreach ($serial_languages as $lang) {
      $result[$lang] = '';
    }
    
    foreach ($abstracts as $a) {
      $lang = $locales[$a['second']];
      $result[$lang] = $a['first'];
    }

    foreach ($result as $lang => $a) {
      if ($a != '') {
        $result_xml = $result_xml.'<abstract locale="'.$lang.'">'.$this->clean($a).'</abstract>';
      }
    }
    return $result_xml;
  }

  public function getLocale($article, $locales) {
    $lang = $article->get('field_language')->getValue();
    if (!empty($lang)) {
      $tid = $lang[0]['target_id'];
      $term = \Drupal\taxonomy\Entity\Term::load($tid);
      $code = $term->get('field_code')->getValue()[0]['value']; 
      return $locales[$code];
    }
    return '';
  }

  public function getPdf($article) {
    $media_array = \Drupal::entityTypeManager()->getStorage('media')->loadByProperties(['field_media_of' => $article->id(), 'bundle' => 'document']);
    if (empty($media_array)) {
      return '';
    }
    $media = reset($media_array);
    $fid = $media->get('field_media_document')->getValue()[0]['target_id'];
    $result = '<id type="internal" advice="ignore">'.$fid.'</id><name locale="###pdf_locale###">PDF</name><seq>0</seq><remote src="';
    $file = \Drupal\file\Entity\File::load($fid);
    $url = 'https://digilib.phil.muni.cz'.$file->createFileUrl();
    $result = $result.$url.'" />';
    return $result;
  }

  public function getAuthorID($article) {
    $authors = $article->get('field_author')->getValue();
    if (empty($authors)) {
      return '1';
    }
    $first = reset($authors);
    $author = \Drupal\node\Entity\Node::load($first['target_id']);
    if (!$author->get('field_author_id')->getValue()) {
      return '1';
    }
    return $author->get('field_author_id')->getValue()[0]['target_id'];
  }

  public function getAuthors($article, $serial_languages) {
    $authors = $article->get('field_author')->getValue();
    $result = '';
    if (empty($authors)) {
      $issue_id = $article->get('field_member_of')->getValue()[0]['target_id'];
      $issue = \Drupal\node\Entity\Node::load($issue_id);
      if ($volume_id = $issue->get('field_member_of')->getValue()[0]['target_id']) {
        $volume = \Drupal\node\Entity\Node::load($volume_id);
        if ($serial_id = $volume->get('field_member_of')->getValue()[0]['target_id']) {
          $serial = \Drupal\node\Entity\Node::load($serial_id);
          $serial_title = $serial->getTitle();
          $result = $result.'<author include_in_browse="true" user_group_ref="Author" seq="1" id="'.$serial_id.'">';
          foreach ($serial_languages as $lang) {
            $result = $result.'<givenname locale="'.$lang.'">Journal</givenname>';
          }
          foreach ($serial_languages as $lang) {
            $result = $result.'<familyname locale="'.$lang.'">'.$serial_title.'</familyname>';
          }
          $result = $result.'<email>email@journals.phil.muni.cz</email></author>';
        }
      }
      return $result;
    }


    foreach ($authors as $key => $item) {
      $author = \Drupal\node\Entity\Node::load($item['target_id']);
      if ($author !== NULL) {
        $name = $author->get('field_name_structured')->getValue();
        $author_id = $author->get('field_author_id')->getValue()[0]['target_id'];
        $given = $name[0]['given'];
        $family = $name[0]['family'];
        $result = $result.'<author include_in_browse="true" user_group_ref="Author" seq="'.($key+1).'" id="'.$author_id.'">';

        foreach ($serial_languages as $lang) {
          $result = $result.'<givenname locale="'.$lang.'">'.$given.'</givenname>';
        }

        foreach ($serial_languages as $lang) {
          $result = $result.'<familyname locale="'.$lang.'">'.$family.'</familyname>';
        }
        $result = $result.'<email>email@journals.phil.muni.cz</email></author>';
      }
    }

    return $result;
  }

  public function getTitles($article, $locales, $serial_languages) {
    $main_title[] = $article->get('field_title_main')->getValue()[0];
    $main_title_value = $main_title[0]['first'];

    $variant_titles = $article->get('field_variant_title')->getValue();
    $titles = array_merge($main_title, $variant_titles);
    $result_xml = '';

    $result = [];
    foreach ($serial_languages as $lang) {
      $result[$lang] = ''; 
    }

    foreach ($titles as $index => &$title) {
      $lang = $locales[$title['second']];
      $result[$lang] = $title['first'];
    }

    foreach ($serial_languages as $lang) {
      if ($result[$lang] == '') {
        $result[$lang] = $main_title_value;
      }
    }

    foreach ($result as $lang => $title) {
      $result_xml = $result_xml.'<title locale="'.$lang.'">'.$this->clean($title)."</title>";
    }
    return $result_xml;
  }

  public function getSerialLanguages($article) {
    $issue_id = $article->get('field_member_of')->getValue()[0]['target_id'];
    $issue = \Drupal\node\Entity\Node::load($issue_id);
    if ($volume_id = $issue->get('field_member_of')->getValue()[0]['target_id']) {
      $volume = \Drupal\node\Entity\Node::load($volume_id);
      if ($serial_id = $volume->get('field_member_of')->getValue()[0]['target_id']) {
        $serial = \Drupal\node\Entity\Node::load($serial_id);
        $handle = $serial->get('field_handle')->getValue()[0]['value'];
        $handle = trim($handle, 'digilib.');
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

  public function clean($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
  }
}
