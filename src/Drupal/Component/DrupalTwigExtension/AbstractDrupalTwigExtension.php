<?php

namespace Drupal\Component\DrupalTwigExtension;

use Drupal\Core\Template\Attribute;
use Twig\Extension\AbstractExtension;

abstract class AbstractDrupalTwigExtension extends AbstractExtension {

  /**
   * {@inheritdoc}
   */
  public function getFunctions() {
    return [
      // This function will receive a renderable array, if an array is detected.
      new \Twig_SimpleFunction('render_var', [$this, 'renderVar']),
      // The url and path function are defined in close parallel to those found
      // in \Symfony\Bridge\Twig\Extension\RoutingExtension
      new \Twig_SimpleFunction('url', [$this, 'getUrl'], ['is_safe_callback' => [$this, 'isUrlGenerationSafe']]),
      new \Twig_SimpleFunction('path', [$this, 'getPath'], ['is_safe_callback' => [$this, 'isUrlGenerationSafe']]),
      new \Twig_SimpleFunction('link', [$this, 'getLink']),
      new \Twig_SimpleFunction('file_url', [$this, 'fileUrl']),
      new \Twig_SimpleFunction('attach_library', [$this, 'attachLibrary']),
      new \Twig_SimpleFunction('active_theme_path', [$this, 'getActiveThemePath']),
      new \Twig_SimpleFunction('active_theme', [$this, 'getActiveTheme']),
      new \Twig_SimpleFunction('create_attribute', [$this, 'createAttribute']),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFilters() {
    return [
      // Translation filters.
      new \Twig_SimpleFilter('t', 't', ['is_safe' => ['html']]),
      new \Twig_SimpleFilter('trans', 't', ['is_safe' => ['html']]),
      // The "raw" filter is not detectable when parsing "trans" tags. To detect
      // which prefix must be used for translation (@, !, %), we must clone the
      // "raw" filter and give it identifiable names. These filters should only
      // be used in "trans" tags.
      // @see TwigNodeTrans::compileString()
      new \Twig_SimpleFilter('placeholder', [$this, 'escapePlaceholder'], ['is_safe' => ['html'], 'needs_environment' => TRUE]),

      // Replace twig's escape filter with our own.
      new \Twig_SimpleFilter('drupal_escape', [$this, 'escapeFilter'], ['needs_environment' => TRUE, 'is_safe_callback' => 'twig_escape_filter_is_safe']),

      // Implements safe joining.
      // @todo Make that the default for |join? Upstream issue:
      //   https://github.com/fabpot/Twig/issues/1420
      new \Twig_SimpleFilter('safe_join', [$this, 'safeJoin'], ['needs_environment' => TRUE, 'is_safe' => ['html']]),

      // Array filters.
      new \Twig_SimpleFilter('without', [$this, 'withoutFilter']),

      // CSS class and ID filters.
      new \Twig_SimpleFilter('clean_class', '\Drupal\Component\Utility\Html::getClass'),
      new \Twig_SimpleFilter('clean_id', '\Drupal\Component\Utility\Html::getId'),
      // This filter will render a renderable array to use the string results.
      new \Twig_SimpleFilter('render', [$this, 'renderVar']),
      new \Twig_SimpleFilter('format_date', [$this, 'formatDate']),
    ];
  }

  /**
   * Generates a URL path given a route name and parameters.
   *
   * @param $name
   *   The name of the route.
   * @param array $parameters
   *   An associative array of route parameters names and values.
   * @param array $options
   *   (optional) An associative array of additional options. The 'absolute'
   *   option is forced to be FALSE.
   *
   * @return string
   *   The generated URL path (relative URL) for the given route.
   *
   * @see \Drupal\Core\Routing\UrlGeneratorInterface::generateFromRoute()
   */
  abstract function getPath($name, $parameters = [], $options = []);

  /**
   * Generates an absolute URL given a route name and parameters.
   *
   * @param $name
   *   The name of the route.
   * @param array $parameters
   *   An associative array of route parameter names and values.
   * @param array $options
   *   (optional) An associative array of additional options. The 'absolute'
   *   option is forced to be TRUE.
   *
   * @return array
   *   A render array with generated absolute URL for the given route.
   *
   * @todo Add an option for scheme-relative URLs.
   */
  abstract function getUrl($name, $parameters = [], $options = []);

  /**
   * Gets a rendered link from a url object.
   *
   * @param string $text
   *   The link text for the anchor tag as a translated string.
   * @param \Drupal\Core\Url|string $url
   *   The URL object or string used for the link.
   * @param array|\Drupal\Core\Template\Attribute $attributes
   *   An optional array or Attribute object of link attributes.
   *
   * @return array
   *   A render array representing a link to the given URL.
   */
  abstract function getLink($text, $url, $attributes = []);

  /**
   * Gets the name of the active theme.
   *
   * @return string
   *   The name of the active theme.
   */
  abstract function getActiveTheme();

  /**
   * Gets the path of the active theme.
   *
   * @return string
   *   The path to the active theme.
   */
  abstract function getActiveThemePath();

  /**
   * Determines at compile time whether the generated URL will be safe.
   *
   * Saves the unneeded automatic escaping for performance reasons.
   *
   * The URL generation process percent encodes non-alphanumeric characters.
   * Thus, the only character within a URL that must be escaped in HTML is the
   * ampersand ("&") which separates query params. Thus we cannot mark
   * the generated URL as always safe, but only when we are sure there won't be
   * multiple query params. This is the case when there are none or only one
   * constant parameter given. For instance, we know beforehand this will not
   * need to be escaped:
   * - path('route')
   * - path('route', {'param': 'value'})
   * But the following may need to be escaped:
   * - path('route', var)
   * - path('route', {'param': ['val1', 'val2'] }) // a sub-array
   * - path('route', {'param1': 'value1', 'param2': 'value2'})
   * If param1 and param2 reference placeholders in the route, it would not
   * need to be escaped, but we don't know that in advance.
   *
   * @param \Twig_Node $args_node
   *   The arguments of the path/url functions.
   *
   * @return array
   *   An array with the contexts the URL is safe
   */
  public function isUrlGenerationSafe(\Twig_Node $args_node) {
    // Support named arguments.
    $parameter_node = $args_node->hasNode('parameters') ? $args_node->getNode('parameters') : ($args_node->hasNode(1) ? $args_node->getNode(1) : NULL);

    if (!isset($parameter_node) || $parameter_node instanceof \Twig_Node_Expression_Array && count($parameter_node) <= 2 &&
        (!$parameter_node->hasNode(1) || $parameter_node->getNode(1) instanceof \Twig_Node_Expression_Constant)) {
      return ['html'];
    }

    return [];
  }

  /**
   * Attaches an asset library to the template, and hence to the response.
   *
   * Allows Twig templates to attach asset libraries using
   * @code
   * {{ attach_library('extension/library_name') }}
   * @endcode
   *
   * @param string $library
   *   An asset library.
   */
  abstract function attachLibrary($library);

  /**
   * Provides a placeholder wrapper around ::escapeFilter.
   *
   * @param \Twig_Environment $env
   *   A Twig_Environment instance.
   * @param mixed $string
   *   The value to be escaped.
   *
   * @return string|null
   *   The escaped, rendered output, or NULL if there is no valid output.
   */
  abstract function escapePlaceholder(\Twig_Environment $env, $string);

  /**
   * Overrides twig_escape_filter().
   *
   * Replacement function for Twig's escape filter.
   *
   * Note: This function should be kept in sync with
   * theme_render_and_autoescape().
   *
   * @param \Twig_Environment $env
   *   A Twig_Environment instance.
   * @param mixed $arg
   *   The value to be escaped.
   * @param string $strategy
   *   The escaping strategy. Defaults to 'html'.
   * @param string $charset
   *   The charset.
   * @param bool $autoescape
   *   Whether the function is called by the auto-escaping feature (TRUE) or by
   *   the developer (FALSE).
   *
   * @return string|null
   *   The escaped, rendered output, or NULL if there is no valid output.
   *
   * @throws \Exception
   *   When $arg is passed as an object which does not implement __toString(),
   *   RenderableInterface or toString().
   *
   * @todo Refactor this to keep it in sync with theme_render_and_autoescape()
   *   in https://www.drupal.org/node/2575065
   */
  abstract function escapeFilter(\Twig_Environment $env, $arg, $strategy = 'html', $charset = NULL, $autoescape = FALSE);

  /**
   * Wrapper around render() for twig printed output.
   *
   * If an object is passed which does not implement __toString(),
   * RenderableInterface or toString() then an exception is thrown;
   * Other objects are casted to string. However in the case that the
   * object is an instance of a Twig_Markup object it is returned directly
   * to support auto escaping.
   *
   * If an array is passed it is rendered via render() and scalar values are
   * returned directly.
   *
   * @param mixed $arg
   *   String, Object or Render Array.
   *
   * @throws \Exception
   *   When $arg is passed as an object which does not implement __toString(),
   *   RenderableInterface or toString().
   *
   * @return mixed
   *   The rendered output or an Twig_Markup object.
   *
   * @see render
   * @see TwigNodeVisitor
   */
  abstract function renderVar($arg);

  /**
   * Joins several strings together safely.
   *
   * @param \Twig_Environment $env
   *   A Twig_Environment instance.
   * @param mixed[]|\Traversable|null $value
   *   The pieces to join.
   * @param string $glue
   *   The delimiter with which to join the string. Defaults to an empty string.
   *   This value is expected to be safe for output and user provided data
   *   should never be used as a glue.
   *
   * @return string
   *   The strings joined together.
   */
  public function safeJoin(\Twig_Environment $env, $value, $glue = '') {
    if ($value instanceof \Traversable) {
      $value = iterator_to_array($value, FALSE);
    }

    return implode($glue, array_map(function ($item) use ($env) {
      // If $item is not marked safe then it will be escaped.
      return $this->escapeFilter($env, $item, 'html', NULL, TRUE);
    }, (array) $value));
  }

  /**
   * Creates an Attribute object.
   *
   * @param array $attributes
   *   (optional) An associative array of key-value pairs to be converted to
   *   HTML attributes.
   *
   * @return \Drupal\Core\Template\Attribute
   *   An attributes object that has the given attributes.
   */
  public function createAttribute(array $attributes = []) {
    return new Attribute($attributes);
  }

  /**
   * Removes child elements from a copy of the original array.
   *
   * Creates a copy of the renderable array and removes child elements by key
   * specified through filter's arguments. The copy can be printed without these
   * elements. The original renderable array is still available and can be used
   * to print child elements in their entirety in the twig template.
   *
   * @param array|object $element
   *   The parent renderable array to exclude the child items.
   * @param string[] ...
   *   The string keys of $element to prevent printing.
   *
   * @return array
   *   The filtered renderable array.
   */
  public function withoutFilter($element) {
    if ($element instanceof \ArrayAccess) {
      $filtered_element = clone $element;
    }
    else {
      $filtered_element = $element;
    }
    $args = func_get_args();
    unset($args[0]);
    foreach ($args as $arg) {
      if (isset($filtered_element[$arg])) {
        unset($filtered_element[$arg]);
      }
    }
    return $filtered_element;
  }

  abstract function fileUrl($uri);

  abstract function formatDate();

}
