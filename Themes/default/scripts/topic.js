var cur_topic_id, cur_msg_id, buff_subject, cur_subject_div, in_edit_mode = 0;
var hide_prefixes = Array();

function modify_topic(topic_id, first_msg_id)
{
	if (!can_ajax)
		return;

	// Add backwards compatibility with old themes.
	if (typeof(cur_session_var) == 'undefined')
		cur_session_var = 'sesc';

	if (in_edit_mode == 1)
	{
		if (cur_topic_id == topic_id)
			return;
		else
			modify_topic_cancel();
	}

	in_edit_mode = 1;
	mouse_on_div = 1;
	cur_topic_id = topic_id;

	if (typeof window.ajax_indicator == "function")
		ajax_indicator(true);
	getXMLDocument(smf_prepareScriptUrl(smf_scripturl) + "action=quotefast;quote=" + first_msg_id + ";modify;xml", onDocReceived_modify_topic);
}

function onDocReceived_modify_topic(XMLDoc)
{
	cur_msg_id = ($('message', XMLDoc).attr('id')[0]).substr(4);

	cur_subject_div = $('#msg_' + cur_msg_id);
	buff_subject = cur_subject_div.html();

	// Here we hide any other things they want hiding on edit.
	set_hidden_topic_areas('none');

	modify_topic_show_edit(XMLDoc.getElementsByTagName("subject")[0].childNodes[0].nodeValue);
	if (typeof window.ajax_indicator == "function")
		ajax_indicator(false);
}

function modify_topic_cancel()
{
	cur_subject_div.html(buff_subject);
	set_hidden_topic_areas('');

	in_edit_mode = 0;
	return false;
}

function modify_topic_save(cur_session_id, cur_session_var)
{
	if (!in_edit_mode)
		return true;

	// Add backwards compatibility with old themes.
	if (typeof(cur_session_var) == 'undefined')
		cur_session_var = 'sesc';

	var i, x = new Array();
	x[x.length] = 'subject=' + document.forms.quickModForm['subject'].value.replace(/&#/g, "&#38;#").php_to8bit().php_urlencode();
	x[x.length] = 'topic=' + parseInt(document.forms.quickModForm.elements['topic'].value);
	x[x.length] = 'msg=' + parseInt(document.forms.quickModForm.elements['msg'].value);

	if (typeof window.ajax_indicator == "function")
		ajax_indicator(true);
	sendXMLDocument(smf_prepareScriptUrl(smf_scripturl) + "action=jsmodify;topic=" + parseInt(document.forms.quickModForm.elements['topic'].value) + ";" + cur_session_var + "=" + cur_session_id + ";xml", x.join("&"), modify_topic_done);

	return false;
}

function modify_topic_done(XMLDoc)
{
	if (!XMLDoc)
	{
		modify_topic_cancel();
		return true;
	}

	var message = $('message', $('smf', XMLDoc)[0])[0];
	var subject = $('subject', message)[0];
	var error = $('error', message)[0];

	if (typeof window.ajax_indicator == 'function')
		ajax_indicator(false);

	if (!subject || error)
		return false;

	subjectText = subject.childNodes[0].nodeValue;

	modify_topic_hide_edit(subjectText);

	set_hidden_topic_areas('');

	in_edit_mode = 0;

	return false;
}

// Simply restore any hidden bits during topic editing.
function set_hidden_topic_areas(set_style)
{
	for (var i = 0; i < hide_prefixes.length; i++)
		$('#' + hide_prefixes[i] + cur_msg_id).css('display', set_style);
}

// *** QuickReply object.
function QuickReply(oOptions)
{
	this.opt = oOptions;
	this.bCollapsed = this.opt.bDefaultCollapsed;
	$('#' + this.opt.sSwitchMode).slideDown(200);
}

// When a user presses quote, put it in the quick reply box (if expanded).
QuickReply.prototype.quote = function (iMessage)
{
	// iMessageId is taken from the owner ID -- quote_button_xxx
	var iMessageId = iMessage && iMessage.id ? iMessage.id.substr(13) : '';

	if (this.bCollapsed)
	{
		window.location.href = smf_prepareScriptUrl(this.opt.sScriptUrl) + 'action=post;quote=' + iMessageId + ';topic=' + this.opt.iTopicId + '.' + this.opt.iStart;
		return false;
	}
	else
	{
		ajax_indicator(true);
		getXMLDocument(smf_prepareScriptUrl(this.opt.sScriptUrl) + 'action=quotefast;quote=' + iMessageId + ';xml;mode=' + (oEditorHandle_message.bRichTextEnabled ? 1 : 0), this.onQuoteReceived);

		// Move the view to the quick reply box.
		window.location.hash = (is_ie ? '' : '#') + this.opt.sJumpAnchor;

		return false;
	}
}

// This is the callback function used after the XMLhttp request.
QuickReply.prototype.onQuoteReceived = function (oXMLDoc)
{
	var sQuoteText = '', o = $('quote', oXMLDoc)[0].childNodes;

	for (var i = 0, j = o.length; i < j; i++)
		sQuoteText += o[i].nodeValue;

	oEditorHandle_message.insertText(sQuoteText, false, true);

	ajax_indicator(false);
}

// The function handling the swapping of the quick reply.
QuickReply.prototype.swap = function ()
{
	$('#' + this.opt.sImageId).attr('src', this.opt.sImagesUrl + "/" + (this.bCollapsed ? this.opt.sImageCollapsed : this.opt.sImageExpanded));
	var cont = $('#' + this.opt.sContainerId);
	this.bCollapsed ? cont.slideDown(200) : cont.slideUp(200);

	this.bCollapsed = !this.bCollapsed;
	return false;
}

// Switch from basic to more powerful editor
QuickReply.prototype.switchMode = function ()
{
	if (this.opt.sBbcDiv != '')
		$('#' + this.opt.sBbcDiv).slideDown(500);
	if (this.opt.sSmileyDiv != '')
		$('#' + this.opt.sSmileyDiv).slideDown(500);
	if (this.opt.sBbcDiv != '' || this.opt.sSmileyDiv != '')
		$('#' + this.opt.sSwitchMode).slideUp(500);
	if (this.bUsingWysiwyg)
		oEditorHandle_message.toggleView(true);
}

// *** QuickModify object.
function QuickModify(oOptions)
{
	this.opt = oOptions;
	this.bInEditMode = false;
	this.sCurMessageId = '';
	this.oCurMessageDiv = null;
	this.oCurSubjectDiv = null;
	this.sMessageBuffer = '';
	this.sSubjectBuffer = '';

	// Show the edit buttons
	if (can_ajax)
		for (var i = document.images.length - 1; i >= 0; i--)
			if (document.images[i].id.substr(0, 14) == 'modify_button_')
				document.images[i].style.display = '';
}

// Function called when a user presses the edit button.
QuickModify.prototype.modifyMsg = function (iMessage)
{
	if (!can_ajax)
		return;

	// iMessageId is taken from the owner ID -- modify_button_xxx
	var iMessageId = iMessage && iMessage.id ? iMessage.id.substr(14) : '';

	// Add backwards compatibility with old themes.
	if (typeof(sSessionVar) == 'undefined')
		sSessionVar = 'sesc';

	// First cancel if there's another message still being edited.
	if (this.bInEditMode)
		this.modifyCancel();

	// At least NOW we're in edit mode
	this.bInEditMode = true;

	// Send out the XMLhttp request to get more info
	ajax_indicator(true);

	getXMLDocument.call(this, smf_prepareScriptUrl(this.opt.sScriptUrl) + 'action=quotefast;quote=' + iMessageId + ';modify;xml', this.onMessageReceived);
}

// The callback function used for the XMLhttp request retrieving the message.
QuickModify.prototype.onMessageReceived = function (XMLDoc)
{
	var sBodyText = '', sSubjectText = '';

	// No longer show the 'loading...' sign.
	ajax_indicator(false);

	// Grab the message ID.
	this.sCurMessageId = $('message', XMLDoc)[0].getAttribute('id');

	// If this is not valid then simply give up.
	if (!document.getElementById(this.sCurMessageId))
		return this.modifyCancel();

	// Replace the body part.
	var o = $('message', XMLDoc)[0].childNodes;
	for (var i = 0, j = o.length; i < j; i++)
		sBodyText += o[i].nodeValue;
	this.oCurMessageDiv = $('#' + this.sCurMessageId)[0];
	this.sMessageBuffer = this.oCurMessageDiv.innerHTML;

	// We have to force the body to lose its dollar signs thanks to IE.
	sBodyText = sBodyText.replace(/\$/g, '{&dollarfix;$}');

	// Actually create the content, with a bodge for disappearing dollar signs.
	this.oCurMessageDiv.innerHTML = this.opt.sTemplateBodyEdit.replace(/%msg_id%/g, this.sCurMessageId.substr(4)).replace(/%body%/, sBodyText).replace(/\{&dollarfix;\$\}/g, '$');

	// Replace the subject part.
	this.oCurSubjectDiv = $('#subject_' + this.sCurMessageId.substr(4))[0];
	this.sSubjectBuffer = this.oCurSubjectDiv.innerHTML;

	sSubjectText = $('subject', XMLDoc)[0].childNodes[0].nodeValue.replace(/\$/g, '{&dollarfix;$}');
	this.oCurSubjectDiv.innerHTML = this.opt.sTemplateSubjectEdit.replace(/%subject%/, sSubjectText).replace(/\{&dollarfix;\$\}/g, '$');

	return true;
}

// Function in case the user presses cancel (or other circumstances cause it).
QuickModify.prototype.modifyCancel = function ()
{
	// Roll back the HTML to its original state.
	if (this.oCurMessageDiv)
	{
		this.oCurMessageDiv.innerHTML = this.sMessageBuffer;
		this.oCurSubjectDiv.innerHTML = this.sSubjectBuffer;
	}

	// No longer in edit mode, that's right.
	this.bInEditMode = false;

	return false;
}

// The function called after a user wants to save his precious message.
QuickModify.prototype.modifySave = function (sSessionId, sSessionVar)
{
	// We cannot save if we weren't in edit mode.
	if (!this.bInEditMode)
		return true;

	// Add backwards compatibility with old themes.
	if (typeof(sSessionVar) == 'undefined')
		sSessionVar = 'sesc';

	var i, x = new Array();
	x[x.length] = 'subject=' + escape(document.forms.quickModForm['subject'].value.replace(/&#/g, "&#38;#").php_to8bit()).replace(/\+/g, "%2B");
	x[x.length] = 'message=' + escape(document.forms.quickModForm['message'].value.replace(/&#/g, "&#38;#").php_to8bit()).replace(/\+/g, "%2B");
	x[x.length] = 'topic=' + parseInt(document.forms.quickModForm.elements['topic'].value);
	x[x.length] = 'msg=' + parseInt(document.forms.quickModForm.elements['msg'].value);

	// Send in the XMLhttp request and let's hope for the best.
	ajax_indicator(true);
	sendXMLDocument.call(this, smf_prepareScriptUrl(this.opt.sScriptUrl) + "action=jsmodify;topic=" + this.opt.iTopicId + ";" + sSessionVar + "=" + sSessionId + ";xml", x.join("&"), this.onModifyDone);

	return false;
}

// Callback function of the XMLhttp request sending the modified message.
QuickModify.prototype.onModifyDone = function (XMLDoc)
{
	// We've finished the loading part.
	ajax_indicator(false);

	// If we didn't get a valid document, just cancel.
	var xm = $('smf', XMLDoc)[0];
	if (!XMLDoc || xm.length < 1)
	{
		// Mozilla will nicely tell us what's wrong.
		if (XMLDoc && XMLDoc.childNodes.length > 0 && XMLDoc.firstChild.nodeName == 'parsererror')
			$('#error_box').html(XMLDoc.firstChild.textContent);
		else
			this.modifyCancel();
		return;
	}

	var message = xm.getElementsByTagName('message')[0];
	var body = message.getElementsByTagName('body')[0];
	var error = message.getElementsByTagName('error')[0];

	if (body)
	{
		// Show new body.
		var bodyText = '';
		for (var i = 0; i < body.childNodes.length; i++)
			bodyText += body.childNodes[i].nodeValue;

		this.sMessageBuffer = this.opt.sTemplateBodyNormal.replace(/%body%/, bodyText.replace(/\$/g, '{&dollarfix;$}')).replace(/\{&dollarfix;\$\}/g,'$');
		this.oCurMessageDiv.innerHTML = this.sMessageBuffer;

		// Show new subject.
		var oSubject = message.getElementsByTagName('subject')[0];
		var sSubjectText = oSubject.childNodes[0].nodeValue.replace(/\$/g, '{&dollarfix;$}');
		this.sSubjectBuffer = this.opt.sTemplateSubjectNormal.replace(/%msg_id%/g, this.sCurMessageId.substr(4)).replace(/%subject%/, sSubjectText).replace(/\{&dollarfix;\$\}/g,'$');
		this.oCurSubjectDiv.innerHTML = this.sSubjectBuffer;

		// If this is the first message, also update the topic subject.
		if (oSubject.getAttribute('is_first') == '1')
			$('#top_subject').html(this.opt.sTemplateTopSubject.replace(/%subject%/, sSubjectText).replace(/\{&dollarfix;\$\}/g, '$'));

		// Show this message as 'modified on x by y'.
		if (this.opt.bShowModify)
			$('#modified_' + this.sCurMessageId.substr(4)).html(message.getElementsByTagName('modified')[0].childNodes[0].nodeValue);
	}
	else if (error)
	{
		$('#error_box').html(error.childNodes[0].nodeValue);
		document.forms.quickModForm.message.style.border = error.getAttribute('in_body') == '1' ? this.opt.sErrorBorderStyle : '';
		document.forms.quickModForm.subject.style.border = error.getAttribute('in_subject') == '1' ? this.opt.sErrorBorderStyle : '';
	}
}

function InTopicModeration(oOptions)
{
	this.opt = oOptions;
	this.bButtonsShown = false;
	this.iNumSelected = 0;

	// Add backwards compatibility with old themes.
	if (typeof(this.opt.sSessionVar) == 'undefined')
		this.opt.sSessionVar = 'sesc';

	// Add checkboxes to all the messages.
	for (var i = 0, n = this.opt.aMessageIds.length; i < n; i++)
	{
		// Create the checkbox.
		var oCheckbox = document.createElement('input');
		oCheckbox.type = 'checkbox';
		oCheckbox.className = 'input_check';
		oCheckbox.name = 'msgs[]';
		oCheckbox.value = this.opt.aMessageIds[i];
		oCheckbox.instanceRef = this;
		oCheckbox.onclick = function () {
			this.instanceRef.handleClick(this);
		}

		// Append it to the container
		var oCheckboxContainer = document.getElementById(this.opt.sCheckboxContainerMask + this.opt.aMessageIds[i]);
		oCheckboxContainer.appendChild(oCheckbox);
		oCheckboxContainer.style.display = '';
	}
}

InTopicModeration.prototype.handleClick = function(oCheckbox)
{
	if (!this.bButtonsShown)
	{
		// Make sure it can go somewhere.
		if (this.opt.sButtonStripDisplay && document.getElementById(this.opt.sButtonStripDisplay))
			document.getElementById(this.opt.sButtonStripDisplay).style.display = "";

		// Add the 'remove selected items' button.
		if (this.opt.bCanRemove)
			smf_addButton(this.opt.sButtonStrip, this.opt.bUseImageButton, {
				sId: this.opt.sSelf + '_remove_button',
				sText: this.opt.sRemoveButtonLabel,
				sImage: this.opt.sRemoveButtonImage,
				sUrl: '#',
				sCustom: ' onclick="return ' + this.opt.sSelf + '.handleSubmit(\'remove\')"'
			});

		// Add the 'restore selected items' button.
		if (this.opt.bCanRestore)
			smf_addButton(this.opt.sButtonStrip, this.opt.bUseImageButton, {
				sId: this.opt.sSelf + '_restore_button',
				sText: this.opt.sRestoreButtonLabel,
				sImage: this.opt.sRestoreButtonImage,
				sUrl: '#',
				sCustom: ' onclick="return ' + this.opt.sSelf + '.handleSubmit(\'restore\')"'
			});

		// Adding these buttons once should be enough.
		this.bButtonsShown = true;
	}

	// Keep stats on how many items were selected.
	this.iNumSelected += oCheckbox.checked ? 1 : -1;

	// Show the number of messages selected in the button.
	if (this.opt.bCanRemove && !this.opt.bUseImageButton)
		$('#' + this.opt.sSelf + '_remove_button')
			.html(this.opt.sRemoveButtonLabel + ' [' + this.iNumSelected + ']')
			.css('display', this.iNumSelected < 1 ? "none" : "");

	if (this.opt.bCanRestore && !this.opt.bUseImageButton)
		$('#' + this.opt.sSelf + '_restore_button')
			.html(this.opt.sRestoreButtonLabel + ' [' + this.iNumSelected + ']')
			.css('display', this.iNumSelected < 1 ? "none" : "");

	// Try to restore the correct position.
	var aItems = document.getElementById(this.opt.sButtonStrip).getElementsByTagName('span');
	if (this.iNumSelected < 1)
	{
		aItems[aItems.length - 3].className = aItems[aItems.length - 3].className.replace(/\s*position_holder/, 'last');
		aItems[aItems.length - 2].className = aItems[aItems.length - 2].className.replace(/\s*position_holder/, 'last');
	}
	else
	{
		aItems[aItems.length - 2].className = aItems[aItems.length - 2].className.replace(/\s*last/, 'position_holder');
		aItems[aItems.length - 3].className = aItems[aItems.length - 3].className.replace(/\s*last/, 'position_holder');
	}

}

InTopicModeration.prototype.handleSubmit = function (sSubmitType)
{
	var oForm = document.getElementById(this.opt.sFormId);

	// Make sure this form isn't submitted in another way than this function.
	var oInput = document.createElement('input');
	oInput.type = 'hidden';
	oInput.name = this.opt.sSessionVar;
	oInput.value = this.opt.sSessionId;
	oForm.appendChild(oInput);

	switch (sSubmitType)
	{
		case 'remove':
			if (!confirm(this.opt.sRemoveButtonConfirm))
				return false;

			oForm.action = oForm.action.replace(/;restore_selected=1/, '');
		break;

		case 'restore':
			if (!confirm(this.opt.sRestoreButtonConfirm))
				return false;

			oForm.action = oForm.action + ';restore_selected=1';
		break;

		default:
			return false;
		break;
	}

	oForm.submit();
	return true;
}

// A global array containing all IconList objects.
var aIconLists = new Array();

// *** IconList object.
function IconList(oOptions)
{
	if (!can_ajax)
		return;

	this.opt = oOptions;
	this.bListLoaded = false;
	this.oContainerDiv = null;
	this.funcMousedownHandler = null;
	this.funcParent = this;
	this.iCurMessageId = 0;
	this.iCurTimeout = 0;

	// Add backwards compatibility with old themes.
	if (!('sSessionVar' in this.opt))
		this.opt.sSessionVar = 'sesc';

	// Replace all message icons by icons with hoverable and clickable div's.
	for (var i = document.images.length - 1, iPrefixLength = this.opt.sIconIdPrefix.length; i >= 0; i--)
		if (document.images[i].id.substr(0, iPrefixLength) == this.opt.sIconIdPrefix)
			setOuterHTML(document.images[i], '<div title="' + this.opt.sLabelIconList + '" onclick="' + this.opt.sBackReference + '.openPopup(this, ' + document.images[i].id.substr(iPrefixLength) + ')" onmouseover="' + this.opt.sBackReference + '.onBoxHover(this, true)" onmouseout="' + this.opt.sBackReference + '.onBoxHover(this, false)" style="background: ' + this.opt.sBoxBackground + '; cursor: pointer; padding: 3px 3px 1px; text-align: center;"><img src="' + document.images[i].src + '" alt="' + document.images[i].alt + '" id="' + document.images[i].id + '" style="margin: 0px; padding: ' + (is_ie ? '3px' : '3px 0 2px') + ';" /></div>');
}

// Event for the mouse hovering over the original icon.
IconList.prototype.onBoxHover = function (oDiv, bMouseOver)
{
	var i = (3 - this.opt.iBoxBorderWidthHover);
	oDiv.style.border = bMouseOver ? this.opt.iBoxBorderWidthHover + 'px solid ' + this.opt.sBoxBorderColorHover : '';
	oDiv.style.background = bMouseOver ? this.opt.sBoxBackgroundHover : this.opt.sBoxBackground;
	oDiv.style.padding = bMouseOver ? i + 'px ' + i + 'px 0' : '3px 3px 1px';
}

// Show the list of icons after the user clicked the original icon.
IconList.prototype.openPopup = function (oDiv, iMessageId)
{
	this.iCurMessageId = iMessageId;

	if (!this.bListLoaded && this.oContainerDiv == null)
	{
		// Create a container div.
		this.oContainerDiv = $('<div></div>', { id: 'iconList' }).css({
			display: 'none',
			cursor: 'pointer',
			position: 'absolute',
			width: oDiv.offsetWidth + 'px',
			background: this.opt.sContainerBackground,
			border: this.opt.sContainerBorder,
			padding: '1px',
			textAlign: 'center'
		}).appendTo('body')[0];

		// Start to fetch its contents.
		ajax_indicator(true);
		getXMLDocument.call(this, smf_prepareScriptUrl(this.opt.sScriptUrl) + 'action=xmlhttp;sa=messageicons;board=' + this.opt.iBoardId + ';xml', this.onIconsReceived);
	}

	// Set the position of the container.
	var aPos = smf_itemPos(oDiv);

	this.oContainerDiv.style.top = (aPos[1] + oDiv.offsetHeight) + 'px';
	this.oContainerDiv.style.left = (aPos[0] - 1) + 'px';
	this.oClickedIcon = oDiv;

	if (this.bListLoaded)
		this.oContainerDiv.style.display = 'block';

	$(document.body).mousedown(this.onWindowMouseDown);
}

// Setup the list of icons once it is received through xmlHTTP.
IconList.prototype.onIconsReceived = function (oXMLDoc)
{
	var icons = oXMLDoc.getElementsByTagName('smf')[0].getElementsByTagName('icon');
	var sItems = '';

	for (var i = 0, n = icons.length; i < n; i++)
		sItems += '<div onmouseover="' + this.opt.sBackReference + '.onItemHover(this, true)" onmouseout="' + this.opt.sBackReference + '.onItemHover(this, false);" onmousedown="' + this.opt.sBackReference + '.onItemMouseDown(this, \'' + icons[i].getAttribute('value') + '\');" style="padding: 3px 0px; margin-left: auto; margin-right: auto; border: ' + this.opt.sItemBorder + '; background: ' + this.opt.sItemBackground + '"><img src="' + icons[i].getAttribute('url') + '" alt="' + icons[i].getAttribute('name') + '" title="' + icons[i].firstChild.nodeValue + '" /></div>';

	this.oContainerDiv.innerHTML = sItems;
	this.oContainerDiv.style.display = 'block';
	this.bListLoaded = true;

	if (is_ie)
		this.oContainerDiv.style.width = this.oContainerDiv.clientWidth + 'px';

	ajax_indicator(false);
}

// Event handler for hovering over the icons.
IconList.prototype.onItemHover = function (oDiv, bMouseOver)
{
	oDiv.style.background = bMouseOver ? this.opt.sItemBackgroundHover : this.opt.sItemBackground;
	oDiv.style.border = bMouseOver ? this.opt.sItemBorderHover : this.opt.sItemBorder;
	if (this.iCurTimeout != 0)
		window.clearTimeout(this.iCurTimeout);
	if (bMouseOver)
		this.onBoxHover(this.oClickedIcon, true);
	else
		this.iCurTimeout = window.setTimeout(this.opt.sBackReference + '.collapseList();', 500);
}

// Event handler for clicking on one of the icons.
IconList.prototype.onItemMouseDown = function (oDiv, sNewIcon)
{
	if (this.iCurMessageId != 0)
	{
		ajax_indicator(true);
		var oXMLDoc = getXMLDocument(smf_prepareScriptUrl(this.opt.sScriptUrl) + 'action=jsmodify;topic=' + this.opt.iTopicId + ';msg=' + this.iCurMessageId + ';' + this.opt.sSessionVar + '=' + this.opt.sSessionId + ';icon=' + sNewIcon + ';xml');
		ajax_indicator(false);

		var oMessage = oXMLDoc.responseXML.getElementsByTagName('smf')[0].getElementsByTagName('message')[0];
		if (oMessage.getElementsByTagName('error').length == 0)
		{
			if (this.opt.bShowModify && oMessage.getElementsByTagName('modified').length != 0)
				$('#modified_' + this.iCurMessageId).html(oMessage.getElementsByTagName('modified')[0].childNodes[0].nodeValue);
			this.oClickedIcon.getElementsByTagName('img')[0].src = oDiv.getElementsByTagName('img')[0].src;
		}
	}
}

// Event handler for clicking outside the list (will make the list disappear).
IconList.prototype.onWindowMouseDown = function ()
{
	for (var i = aIconLists.length - 1; i >= 0; i--)
		aIconLists[i].collapseList.call(aIconLists[i].funcParent);
}

// Collapse the list of icons.
IconList.prototype.collapseList = function()
{
	this.onBoxHover(this.oClickedIcon, false);
	this.oContainerDiv.style.display = 'none';
	this.iCurMessageId = 0;
	$(document.body).mousedown(this.onWindowMouseDown);
}


// *** Other functions...
function expandThumb(thumbID)
{
	var img = $('#thumb_' + thumbID)[0];
	var link = $('#link_' + thumbID)[0];
	var tmp = img.src;
	img.src = link.href;
	link.href = tmp;
	img.style.width = '';
	img.style.height = '';
	return false;
}

var current_user_menu = null;

// *** The UserMenu
function UserMenu(oList)
{
	this.list = oList;
}

UserMenu.prototype.switchMenu = function (oLink)
{
	var details = oLink && oLink.id ? oLink.id.substr(2).split('_') : [0, 0];
	var iMsg = details[0], iUserId = details[1];

	if (current_user_menu != null)
	{
		$('#userMenu' + current_user_menu).remove();
		if (current_user_menu == iMsg)
		{
			current_user_menu = null;
			return false;
		}
	}
	current_user_menu = iMsg;
	if (!(this.list['user' + iUserId]))
		return false;
	for (var i = 0, sHTML = '', aLinkList = this.list['user' + iUserId], j = aLinkList.length; i < j; i++)
	{
		if (aLinkList[i][0].charAt[0] == '?')
			aLinkList[i][0] = smf_scripturl + aLinkList[i][0];

		sHTML += '<div class="usermenuitem windowbg"><a href="' + aLinkList[i][0].replace(/%msg%/, iMsg) + '">' + aLinkList[i][1] + '</a></div>';
	}
	var pos = smf_itemPos(oLink);
	$('<div></div>', { id: 'userMenu' + iMsg, 'class': 'usermenu' }).html(sHTML).appendTo('body')
		.css({ display: 'block', left: pos[0] + 'px', top: (pos[1] + oLink.offsetHeight) + 'px' })
		.mouseleave(function () { oUserMenu.switchMenu(); current_user_menu = null; });
	return false;
}
