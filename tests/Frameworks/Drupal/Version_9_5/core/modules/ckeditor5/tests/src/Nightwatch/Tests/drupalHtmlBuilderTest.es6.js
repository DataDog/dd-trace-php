const assert = require('assert');
const fs = require('fs');
const path = require('path');
// eslint-disable-next-line import/no-extraneous-dependencies
const { JSDOM } = require('jsdom');

// Nightwatch doesn't support ES modules. This workaround loads the class
// directly here.
// @todo remove this after https://www.drupal.org/project/drupal/issues/3247647
//   has been resolved.
// eslint-disable-next-line no-eval
const DrupalHtmlBuilder = eval(
  `(${fs
    .readFileSync(
      path.resolve(
        __dirname,
        '../../../../js/ckeditor5_plugins/drupalHtmlEngine/src/drupalhtmlbuilder.js',
      ),
    )
    .toString()})`.replace('export default', ''),
);
const { document, Node } = new JSDOM(`<!DOCTYPE html>`).window;

module.exports = {
  '@tags': ['ckeditor5'],
  '@unitTest': true,
  'should return empty string when empty DocumentFragment is passed':
    function () {
      const drupalHtmlBuilder = new DrupalHtmlBuilder();
      drupalHtmlBuilder.appendNode(document.createDocumentFragment());
      assert.equal(drupalHtmlBuilder.build(), '');
    },
  'should create text from single text node': function () {
    const drupalHtmlBuilder = new DrupalHtmlBuilder();
    const text = 'foo bar';
    const fragment = document.createDocumentFragment();
    const textNode = document.createTextNode(text);
    fragment.appendChild(textNode);

    drupalHtmlBuilder.appendNode(fragment);
    assert.equal(drupalHtmlBuilder.build(), text);
  },
  'should return correct HTML from fragment with paragraph': function () {
    const drupalHtmlBuilder = new DrupalHtmlBuilder();
    const fragment = document.createDocumentFragment();
    const paragraph = document.createElement('p');
    paragraph.textContent = 'foo bar';
    fragment.appendChild(paragraph);

    drupalHtmlBuilder.appendNode(fragment);
    assert.equal(drupalHtmlBuilder.build(), '<p>foo bar</p>');
  },
  'should return correct HTML from fragment with multiple child nodes':
    function () {
      const drupalHtmlBuilder = new DrupalHtmlBuilder();
      const fragment = document.createDocumentFragment();
      const text = document.createTextNode('foo bar');
      const paragraph = document.createElement('p');
      const div = document.createElement('div');

      paragraph.textContent = 'foo';
      div.textContent = 'bar';

      fragment.appendChild(text);
      fragment.appendChild(paragraph);
      fragment.appendChild(div);

      drupalHtmlBuilder.appendNode(fragment);

      assert.equal(
        drupalHtmlBuilder.build(),
        'foo bar<p>foo</p><div>bar</div>',
      );
    },
  'should return correct HTML from fragment with comment': function () {
    const drupalHtmlBuilder = new DrupalHtmlBuilder();
    const fragment = document.createDocumentFragment();
    const div = document.createElement('div');
    const comment = document.createComment('bar');
    div.textContent = 'bar';

    fragment.appendChild(div);
    fragment.appendChild(comment);

    drupalHtmlBuilder.appendNode(fragment);

    assert.equal(drupalHtmlBuilder.build(), '<div>bar</div>');
  },
  'should return correct HTML from fragment with attributes': function () {
    const drupalHtmlBuilder = new DrupalHtmlBuilder();
    const fragment = document.createDocumentFragment();
    const div = document.createElement('div');
    div.setAttribute('id', 'foo');
    div.classList.add('bar');
    div.textContent = 'baz';

    fragment.appendChild(div);
    drupalHtmlBuilder.appendNode(fragment);

    assert.equal(
      drupalHtmlBuilder.build(),
      '<div id="foo" class="bar">baz</div>',
    );
  },
  'should return correct HTML from fragment with self closing tag':
    function () {
      const drupalHtmlBuilder = new DrupalHtmlBuilder();
      const fragment = document.createDocumentFragment();
      const hr = document.createElement('hr');

      fragment.appendChild(hr);
      drupalHtmlBuilder.appendNode(fragment);

      assert.equal(drupalHtmlBuilder.build(), '<hr>');
    },
  'attribute values should be escaped': function () {
    const drupalHtmlBuilder = new DrupalHtmlBuilder();
    const fragment = document.createDocumentFragment();
    const div = document.createElement('div');
    div.setAttribute('data-caption', 'Kittens & llamas are <em>cute</em>');
    div.textContent = 'foo';

    fragment.appendChild(div);
    drupalHtmlBuilder.appendNode(fragment);

    assert.equal(
      drupalHtmlBuilder.build(),
      '<div data-caption="Kittens &amp; llamas are &lt;em&gt;cute&lt;/em&gt;">foo</div>',
    );
  },
};
