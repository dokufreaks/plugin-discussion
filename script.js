/**
 * Javascript functionality for the discussion plugin
 */

/**
 * Check if a field is blank
 */
function isBlank(s){
  if ((s === null) || (s.length === 0)){
    return true;
  }

  for (var i = 0; i < s.length; i++){
    var c = s.charAt(i);
	  if ((c != ' ') && (c != '\n') && (c != '\t')){
	    return false;
    }
  }
  return true;
}

/**
 * Validate an input field
 */
function validate(form){
    if(!form) return;

    if (isBlank(form.name.value)){
        form.name.focus();
        form.name.style.backgroundColor = '#fcc';
        return false;
    } else {
        form.name.style.backgroundColor = '#fff';
    }
    if (isBlank(form.mail.value) || form.mail.value.indexOf("@") == -1){
        form.mail.focus();
        form.mail.style.backgroundColor = '#fcc';
        return false;
    } else {
        form.mail.style.backgroundColor = '#fff';
    }
    if (isBlank(form.text.value)){
        form.text.focus();
        form.text.style.borderColor = '#fcc';
        return false;
    }
}

/**
 * AJAX preview
 *
 * @author Michael Klier <chi@chimeric.de>
 */
function discussion_ajax_preview() {
    if(!document.getElementById) return;

    var textarea = $('discussion__comment_text');
    var comment = textarea.value;
    if(!comment) return;

    var preview = $('discussion__comment_preview');
    preview.innerHTML = '<img src="'+DOKU_BASE+'/lib/images/throbber.gif" />';

    // We use SACK to do the AJAX requests
    var ajax = new sack(DOKU_BASE+'lib/exe/ajax.php');
    ajax.AjaxFailedAlert = '';
    ajax.encodeURIString = false;
    ajax.setVar('call', 'discussion_preview');
    ajax.setVar('comment', comment);

    // define callback
    ajax.onCompletion = function(){
        var data = this.response;
        if(data === ''){ return; }
        preview.style.visibility = 'hidden';
        preview.innerHTML = data;
        preview.style.visibility = 'visible';
    };

    ajax.runAJAX();
}

// init toolbar
addInitEvent(function() {
    if(typeof window.initToolbar == 'function') {
        initToolbar("discussion__comment_toolbar", "discussion__comment_text", toolbar);
    }
});

// init preview button
addInitEvent(function() {
    var btn = $('discussion__btn_preview');
    if(!btn) return;
    addEvent(btn, 'click', discussion_ajax_preview);
});

// init field check
addInitEvent(function() {
    var form = $('discussion__comment_form');
    if(!form) return;
    addEvent(form, 'submit', function() { return validate(form); });
});

function sendDiscussionForm(e) {
  var form = this;
  var ajax = new sack(DOKU_BASE + 'lib/exe/ajax.php');

  if(ajax.failed) { return true; }
  ajax.encodeURIString = false;

  ajax.setVar('call', 'discussion');

  for(var i = 0; i < form.length; i++) {
    if (form.elements[i].name.length > 1) {
      ajax.setVar(form.elements[i].name, encodeURIComponent(form.elements[i].value));
    }
  }


  ajax.elementObj = document.createElement('div');

  ajax.onCompletion = function () {
    this.elementObj.className = 'ajaxContainer';
    var container = form.parentNode;
    while (!(/(hentry|comment_form)/).test(container.className)) {
      container = container.parentNode;
    }
    var replies = false;
    if (container.nextSibling) {
      replies = container.nextSibling;
      while (replies.nextSibling && !(/(comment_replies|hentry|comment_form)/).test(replies.className)) {
        replies = replies.nextSibling;
      }
    }

    switch (form.comment.value) {
    case 'delete':
      if (replies && replies.className == 'comment_replies') {
        replies.parentNode.removeChild(replies);
      }
      container.parentNode.removeChild(container);
      break;
    case 'reply':
      if (!replies || replies.className != 'comment_replies') {
        var newReplies = document.createElement('div');
        newReplies.className = 'comment_replies';
        newReplies.style.marginLeft = '26.6667px';
        if (replies) {
          container.parentNode.insertBefore(newReplies, replies);
        } else {
          container.parentNode.insertBefore(newReplies, null);
        }
        replies = newReplies;
      }
      replies.insertBefore(this.elementObj, replies.firstChild);
      break;
    case 'add':
      container.parentNode.appendChild(this.elementObj);
      container.parentNode.removeChild(container);
      this.elementObj.scrollIntoView();
      break;
    default: /* toggle, edit, save */
      container.parentNode.replaceChild(this.elementObj, container);
      break;
    }
  };
  if (form.comment.value != 'delete') {
    ajax.afterCompletion = function () {
      if (form.comment.value == 'reply') {
        window.setTimeout('cleanAjax(true)', 400);
      } else {
        window.setTimeout('cleanAjax(false)', 400);
      }
    };
  }

  ajax.runAJAX();

  e.preventDefault();
  return false;
}

function cleanAjax(reply) {
  var elementObj = getElementsByClass('ajaxContainer', document, 'div')[0];
  var form = getElementsByClass('comment_form', elementObj, 'div')[0];
  var hentry = getElementsByClass('hentry', elementObj, 'div')[0];
  if (form) {
    elementObj.parentNode.replaceChild(form, elementObj);
     if (reply) {
       var save_button = getElementsByClass('comment_submit', form, 'input')[0];
       var cancel_link = document.createElement('a');
       cancel_link.innerHTML = 'Abbrechen';
       cancel_link.href = '#';
       save_button.parentNode.insertBefore(cancel_link, save_button.nextSibling);
       addEvent(cancel_link, 'click', function (e) {
         var container = this.parentNode;
         while (!(/comment_form/).test(container.className)) {
           container = container.parentNode;
         }
         container.parentNode.removeChild(container);
         e.preventDefault();
         return false;
       });
     }
  } else if (hentry) {
    elementObj.parentNode.replaceChild(hentry, elementObj);
  }
  solve_captcha();
  installSendDiscussionForm();
}

function installSendDiscussionForm() {
  var wrapper = getElementsByClass('comment_wrapper', document, 'div');
  for(var i = 0; i < wrapper.length; i++) {
    var forms = wrapper[i].getElementsByTagName('form');
    for (var f = 0; f < forms.length; f++) {
      addEvent(forms[f], 'submit', sendDiscussionForm);
    }
  }
}

addInitEvent(installSendDiscussionForm);
