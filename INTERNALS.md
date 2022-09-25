This is an anthroplogical attempt to document how this plugin works. Thus, 
details may be incorrect.


# action.php

`action.php` is where the meatiest parts of this plugin live. As is usual, this 
file defines a class `action_plugin_discussion` as a linear subclass of 
`DokuWiki_Action_Plugin`.

This class registers itself on 11 hooks:

* before `ACTION_ACT_PREPROCESS`, to handle comment actions and dispatch data 
  processing routines; in other words, this is where comments are added, updated, 
  deleted, 'toogled'; and also where subscriptions are managed.
* after `TPL_ACT_RENDER`; this deceptively brief callback renders the comment 
  components on relevant screens
* after `INDEXER_PAGE_ADD`; this indexes comments for search
* before `FULLTEXT_SNIPPET_CREATE`; this also indexes comments for search
* before `INDEXER_VERSION_GET`; this returns the static *string* `0.1`
* after `FULLTEXT_PHRASE_MATCH`; this excutes full text search against comments
* after `PARSER_METADATA_RENDER`; this commits comment metadata (status, title) changes to disk
* before `TPL_METAHEADER_OUTPUT`; this populates the discussion toolbar JS?
* after `TOOLBAR_DEFINE`; populates toolbar buttons for discussions
* before `AJAX_CALL_UNKNOWN`; handle AJAXy preview requests
* before `TPL_TOC_RENDER`; adds discusions to page TOC
