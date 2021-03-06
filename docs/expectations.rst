Writing Expectations
====================

Expectations are **expressions** evaluated against the current HTTP response
and if one of them returns a falsy value, Blackfire Player throws a
``Blackfire\Player\Exception\ExpectationFailureException`` exception.

.. note::

    Learn more about `Expressions syntax
    <http://symfony.com/doc/current/components/expression_language/syntax.html>`_
    in the Symfony documentation.

Expressions have access to the following functions:

* ``status_code()``: The HTTP status code for the current HTTP response;

* ``header()``: Returns the value of an HTTP header;

* ``body()``: The HTTP body for the current HTTP response;

* ``css()``: Returns nodes matching the CSS selector (for HTML responses);

* ``xpath()``: Returns nodes matching the XPath selector (for HTML and XML
  responses);

* ``json()``: Returns JSON elements matching the CSS expression.

The ``css()`` and ``xpath()`` functions return
``Symfony\Component\DomCrawler\Crawler`` instances. Learn more about `methods
you can call on Crawler instances
<http://symfony.com/doc/current/components/dom_crawler.html>`_; the ``json()``
function returns a PHP array.

The ``json()`` function accepts `JMESPath
<http://jmespath.org/specification.html>`_. This is only supported when
``mtdowling/jmespath.php": "^2.2"`` is available as a project Composer
dependency or if you are using the Player phar.

Here are some common expressions:

.. code-block:: yaml

    # return all HTML nodes matching ".post h2 a"
    - css(".post h2 a")

    # return the text of the first node matching ".post h2 a"
    - css(".post h2 a").first().text()

    # return the href attribute of the first node matching ".post h2 a"
    - css(".post h2 a").first().attr("href")

    # check that "h1" contains "Welcome"
    - css("h1:contains('Welcome')").count() > 0

    # same as above
    - css("h1").first().text() matches "/Welcome/"

    # return the Age request HTTP header
    - header("Age")

    # check that the HTML body contains "Welcome"
    - body() matches "/Welcome/"

    # extract a value
    - json("_links.store.href")

    # extract keys
    - json("arguments."sql.pdo.queries".keys(@)")
