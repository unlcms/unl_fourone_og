<?php

/**
 * Implements template_preprocess_page().
 */
function unl_fourone_og_preprocess_page(&$vars, $hook) {
  if (module_exists('og_context')) {
    // Set site_name to Group's display name.
    $group_context = og_context();

    // Make sure that the current page has a group associated with it.
    if ($group_context && $group = node_load($group_context['gid'])) {
      $vars['site_name'] = $group->title;
    }
  }

  // Clear the site_slogan for the main group
  if (unl_fourone_og_get_current_group() === false) {
    $vars['site_slogan'] = '';
  }
  $group = unl_fourone_og_get_current_group();
  if ($group) {
    $front_nid = unl_fourone_og_get_front_group_id();
    if ($group->nid == $front_nid) {
      $vars['site_slogan'] = '';
    }
  }
}

/**
 * Implements hook_html_head_alter().
 */
function unl_fourone_og_html_head_alter(&$head_elements) {
  // Add a <link rel="home"> tag with the current group as the href attribute.
  $group = unl_fourone_og_get_current_group();
  if (!$group) {
    return;
  }
  $front_nid = unl_fourone_og_get_front_group_id();

  if (isset($group) && $group && isset($front_nid) && (int)$group->nid !== (int)$front_nid) {
    $href = 'node/' . $group->nid;
  }
  else {
    $href = '';
  }

  $head_elements['drupal_add_html_head_link:home'] = array(
    '#tag' => 'link',
    '#attributes' => array(
      'rel' => 'home',
      'href' => url($href, array('absolute' => TRUE)),
    ),
    '#type' => 'html_tag',
  );
}

/**
 * Implements hook_menu_breadcrumb_alter().
 */
function unl_fourone_og_menu_breadcrumb_alter(&$active_trail, $item) {
  $group = unl_fourone_og_get_current_group();
  if ($group) {
    $front_nid = unl_fourone_og_get_front_group_id();
    // Only splice in the current group if the current group is not the main/front group.
    if ($group->nid !== $front_nid) {
      $group_breadcrumb = array(
        'title' => $group->title,
        'href' => 'node/' . $group->nid,
        'link_path' => '',
        'localized_options' => array(),
        'type' => 0,
      );
      array_splice($active_trail, 1, 0, array($group_breadcrumb));
    }
  }
}

/**
 * Implements theme_breadcrumb().
 */
function unl_fourone_og_breadcrumb($variables) {
  if ($group = unl_fourone_og_get_current_group()) {
    $node = menu_get_object();
    if ($group->nid !== unl_fourone_og_get_front_group_id() && isset($node) && $node->type == 'group') {
      array_pop($variables['breadcrumb']);
      // At this point, on a group homepage the breadcrumb trail should consist of one item: Home
      // This is sort of a hack below for cases where the group homepage is somewhere in a menu and an extra breadcrumb is being added.
      if (count($variables['breadcrumb']) == 2) {
        array_pop($variables['breadcrumb']);
      }
    }
  }

  if (count($variables['breadcrumb']) == 0) {
    $variables['breadcrumb'][] = '<a href="' . url('<front>') . '">' . check_plain(unl_fourone_get_site_name_abbreviated()) . '</a>';
  }
  else {
    // Change 'Home' to be $site_name
    array_unshift($variables['breadcrumb'],
        str_replace('Home', check_plain(unl_fourone_get_site_name_abbreviated()),
            array_shift($variables['breadcrumb'])));
  }

  //Add the intermediate breadcrumbs if they exist
  $intermediateBreadcrumbs = theme_get_setting('intermediate_breadcrumbs');
  if (is_array($intermediateBreadcrumbs)) {
    krsort($intermediateBreadcrumbs);
    foreach ($intermediateBreadcrumbs as $intermediateBreadcrumb) {
      if ($intermediateBreadcrumb['text'] && $intermediateBreadcrumb['href']) {
        array_unshift($variables['breadcrumb'], '<a href="' . $intermediateBreadcrumb['href'] . '">' . check_plain($intermediateBreadcrumb['text']) . '</a>');
      }
    }
  }

  // Prepend UNL
  array_unshift($variables['breadcrumb'], '<a href="http://www.unl.edu/">Nebraska</a>');

  // Append title of current page -- http://drupal.org/node/133242
  if (!drupal_is_front_page()) {
    if ($group = unl_fourone_og_get_current_group()) {
      $node = menu_get_object();
      if ($group->nid !== unl_fourone_og_get_front_group_id() && isset($node) && $node->type == 'group') {
        $group_alias = drupal_get_path_alias('node/'.$node->nid);
        $group_name = $node->title;
      }
    }

    $variables['breadcrumb'][] = (isset($group_alias) ? '<a href="'.$group_alias.'">' : '') .
        (isset($group_name) ? $group_name : check_plain(menu_get_active_title())) .
        (isset($group_alias) ? '</a>' : '');
  }

  $html = '<ul>' . PHP_EOL;
  foreach ($variables['breadcrumb'] as $breadcrumb) {
    $html .= '<li>' .  $breadcrumb . '</li>' . PHP_EOL;
  }
  $html .= '</ul>';

  return $html;
}

/**
 * Custom function that returns the group node of the current group context.
 */
function unl_fourone_og_get_current_group() {
  if (module_exists('og_context')) {
    $group_context = og_context();
    $view = views_get_page_view();
    // Set the og context if we are viewing a Views Page display with one of the
    //  contextual filters (in the switch) combined with an "OG membership" relationship.
    if (empty($group_context) && $view = views_get_page_view()) {
      if ($view->display_handler->plugin_name == 'page') {
        foreach($view->argument as $key => $value) {
          switch ($key) {
            case 'gid':
              og_context('node', node_load($view->argument['gid']->argument));
              $group_context = og_context();
              break;
            case 'title':
              $query = new EntityFieldQuery();
              $entities = $query->entityCondition('entity_type', 'node')
                ->propertyCondition('title', $view->argument['title']->argument)
                ->propertyCondition('status', 1)
                ->range(0,1)
                ->execute();
              if (!empty($entities['node'])) {
                $keys = array_keys($entities['node']);
                og_context('node', node_load(array_shift($keys)));
                $group_context = og_context();
              }
              break;
            // This is a hack using a field with the specific name 'field_url_path' because
            //  relying on path alias does not work: https://drupal.org/node/1658352#comment-7927861
            case 'field_url_path_value':
              $query = new EntityFieldQuery();
              $entities = $query->entityCondition('entity_type', 'node')
                ->fieldCondition('field_url_path', 'value', $view->argument['field_url_path_value']->argument)
                ->propertyCondition('status', 1)
                ->range(0,1)
                ->execute();
              if (!empty($entities['node'])) {
                $keys = array_keys($entities['node']);
                og_context('node', node_load(array_shift($keys)));
                $group_context = og_context();
              }
              break;
          }
        }
      }
    }

    if ($group_context) {
      return node_load($group_context['gid']);
    }
  }
  return false;
}

/**
 * Custom function that returns the nid of the group being used for <front>.
 */
function unl_fourone_og_get_front_group_id() {
  $front_nid = 0;
  $front_url = drupal_get_normal_path(variable_get('site_frontpage', 'node'));
  $front_url = trim($front_url, '/');
  $front = explode('/', $front_url);
  if (isset($front[0], $front[1]) && $front[0]=='node' && ctype_digit($front[1])) {
    $front_nid = $front[1];
  }
  return $front_nid;
}

/**
 * Set og context for view pages
 *
 * implements hook_views_pre_render
 *
 * @param $view
 */
function unl_fourone_og_views_pre_render(&$view) {
  unl_fourone_og_get_current_group();
}
