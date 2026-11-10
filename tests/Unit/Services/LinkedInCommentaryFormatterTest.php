<?php

use App\Services\LinkedIn\LinkedInCommentaryFormatter;

beforeEach(function () {
    $this->formatter = new LinkedInCommentaryFormatter;
});

it('leaves plain text unchanged', function () {
    expect($this->formatter->format("Hello LinkedIn.\nNew line, commas; and dots."))->toBe("Hello LinkedIn.\nNew line, commas; and dots.");
});

it('escapes every reserved character', function () {
    expect($this->formatter->format('a|b{c}d@e[f]g(h)i<j>k\\l*m_n~o'))
        ->toBe('a\|b\{c\}d\@e\[f\]g\(h\)i\<j\>k\\\\l\*m\_n\~o');
});

it('turns hashtags into LinkedIn hashtags', function () {
    expect($this->formatter->format('Read this #Laravel #php_tips post'))
        ->toBe('Read this {hashtag|\#|Laravel} {hashtag|\#|php\_tips} post');
});

it('does not treat a # inside a word or on its own as a hashtag', function () {
    expect($this->formatter->format('C# and issue# and # alone'))
        ->toBe('C\# and issue\# and \# alone');
});

it('keeps non-English hashtags', function () {
    expect($this->formatter->format('#پاکستان'))->toBe('{hashtag|\#|پاکستان}');
});
