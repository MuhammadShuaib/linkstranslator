var assert = require('assert');
var resolve = require('./resolve.js');
var passed = 0;

resolve(['سیب'], 'urwiki', 'enwiki').then(function (result) {
  assert.equal(result['سیب'], 'Apple');
  passed++;
});

resolve(['apple'], 'enwiki', 'urwiki').then(function (result) {
  assert.equal(result['apple'], 'سیب');
  passed++;
});

process.on('exit', function () {
  console.log('Assetions passed: ', passed);
});
