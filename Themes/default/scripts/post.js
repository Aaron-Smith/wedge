/*!
 * Wedge
 *
 * Helper functions for manipulating text and sending posts
 *
 * @package wedge
 * @copyright 2010-2012 Wedgeward, wedge.org
 * @license http://wedge.org/license/
 *
 * @version 0.1
 */

// Split a quote (or any unclosed tag) if we press Shift+Enter inside it.
function splitQuote(e)
{
	// Did we just press Shift+Enter or Ctrl+Enter?
	if (e.which != 13 || (!e.shiftKey && !e.ctrlKey))
		return true;

	// Where are we, already?
	if ('selectionStart' in this)
		var selectionStart = this.selectionStart;
	else
	{
		var selectionStart, range = document.selection.createRange(), dul = range.duplicate();
		dul.moveToElementText(this);
		dul.setEndPoint('EndToEnd', range);
		selectionStart = dul.text.length - range.text.length;
	}

	var
		selection = this.value.substr(0, selectionStart), lcs = selection.toLowerCase(), nextBreak, has_slash,
		lcsl = lcs.length, pos = 0, tag, bbcode, taglist = [], baretags = [], baretag, extag, log_tags = true,
		that = this.instanceRef, protect_tags = that.opt.aProtectTags, closed_tags = that.opt.aClosedTags;

	// Build a list of opened tags...
	while (true)
	{
		pos = lcs.indexOf('[', pos) + 1;
		if (!pos)
			break;
		tag = selection.substring(pos, lcs.indexOf(']', pos + 1));
		has_slash = tag[0] == '/';
		bbcode = tag.substr(+has_slash);
		baretag = ((nextBreak = /[\s=]/.exec(bbcode)) ? bbcode.substr(0, bbcode.indexOf(nextBreak)) : bbcode).toLowerCase();

		// Is it a closer tag?
		if (has_slash)
		{
			// Maybe it's a loose tag. Ignore it.
			if (!taglist.length)
				break;

			// Or maybe we're looking for a protected tag's closer. If it isn't it, skip it.
			if (!log_tags && baretag != baretags[baretags.length - 1])
				continue;

			// Otherwise, empty the stack until we find the equivalent opener. Normally, immediately.
			do
			{
				taglist.pop();
				extag = baretags.pop();
				log_tags |= in_array(extag, protect_tags);
			}
			while (extag && baretag != extag);
		}
		// Then it's an opener tag. If we're not within a protected tag loop,
		// and it's not a self-closed tag, add it to the tag stack.
		else if (log_tags && !in_array(baretag, closed_tags) && /[^a-zA-Z0-9]/.exec(baretag) === null)
		{
			taglist.push(bbcode);
			baretags.push(baretag);

			// If we just met a protected opener, like [code], we'll ignore all further tags until we find a closer for it.
			log_tags &= !in_array(baretag, protect_tags);
		}
	}

	if (baretags.length)
		that.surroundText('[/' + baretags.reverse().join('][/') + ']\n', '\n\n[' + taglist.join('][') + ']', this);

	return true;
};

String.prototype.easyReplace = function (oReplacements)
{
	var sResult = this, sSearch;
	for (sSearch in oReplacements)
		sResult = sResult.replace(new RegExp('%' + sSearch + '%', 'g'), oReplacements[sSearch]);

	return sResult;
};


/*
	A smiley is worth
	a thousands words.
*/

function weSmileyBox(opt)
{
	var that = this;
	that.opt = opt;
	that.oSmileyRowsContent = {};

	// Get the HTML content of the smileys visible on the post screen.
	that.getSmileyRowsContent('postform');

	// Inject the HTML.
	$('#' + opt.sContainer).html(opt.sSmileyBoxTemplate.easyReplace({
		smileyRows: that.oSmileyRowsContent.postform,
		moreSmileys: opt.oSmileyLocations.popup.length == 0 ? '' : opt.sMoreSmileysTemplate.easyReplace({
			moreSmileysId: opt.sContainer + '_addMoreSmileys'
		})
	}));

	// Initialize the smileys.
	that.initSmileys('postform');

	// Initialize the [more] button.
	if (opt.oSmileyLocations.popup.length)
		$('#' + opt.sContainer + '_addMoreSmileys').click(function () {
			$(this).hide();

			// Get the popup smiley HTML, add the new smileys to the list and activate them.
			that.getSmileyRowsContent('popup');
			$('#' + opt.sContainer + ' .more').hide().html(that.oSmileyRowsContent.popup).slideDown();
			that.initSmileys('popup');

			return false;
		});
}

// Loop through the smileys to setup the HTML.
weSmileyBox.prototype.getSmileyRowsContent = function (sLocation)
{
	// If it's already defined, don't bother.
	if (sLocation in this.oSmileyRowsContent)
		return;

	this.oSmileyRowsContent[sLocation] = '';
	var that = this, opt = this.opt;

	$.each(opt.oSmileyLocations[sLocation], function (iSmileyRowIndex)
	{
		var sSmileyRowContent = '';
		$.each(this, function (iSmileyIndex)
		{
			sSmileyRowContent += opt.sSmileyTemplate.easyReplace({
				smileySource: this[1].php_htmlspecialchars(),
				smileyDesc: this[2].php_htmlspecialchars(),
				smileyCode: this[0].php_htmlspecialchars(),
				smileyId: opt.sContainer + '_' + sLocation + '_' + iSmileyRowIndex + '_' + iSmileyIndex
			});
		});

		that.oSmileyRowsContent[sLocation] += opt.sSmileyRowTemplate.easyReplace({
			smileyRow: sSmileyRowContent
		});
	});
};

weSmileyBox.prototype.initSmileys = function (sLocation)
{
	var that = this;
	$.each(that.opt.oSmileyLocations[sLocation], function (iSmileyRowIndex)
	{
		$.each(this, function (iSmileyIndex)
		{
			$('#' + that.opt.sContainer + '_' + sLocation + '_' + iSmileyRowIndex + '_' + iSmileyIndex)
				.css('cursor', 'pointer')
				.click(function () {
					// Dissect the id to determine its exact smiley properties.
					var aMatches = this.id.match(/([^_]+)_(\d+)_(\d+)$/);
					if (aMatches.length == 4 && that.opt.sClickHandler)
						that.opt.sClickHandler(that.opt.oSmileyLocations[aMatches[1]][aMatches[2]][aMatches[3]]);

					return false;
				});
		});
	});
};

/*
	The BBC button box.
	Press 1 for Doctor Who,
	and 2 for Red Dwarf.
*/

function weButtonBox(opt)
{
	this.opt = opt;

	var sBbcContent = '';
	$.each(opt.aButtonRows, function (iButtonRowIndex)
	{
		var sRowContent = '', bPreviousWasDivider = false;

		$.each(this, function (iButtonIndex)
		{
			var is_sprite = $.isArray(this[2]);

			// this[0] = sType, 1 = bEnabled, 2 = sImage or sPos, 3 = sCode, 4 = sBefore, 5 = sAfter, 6 = sDescription
			if (this[0] == 'button')
			{
				if (this[1])
				{
					sRowContent += opt.sButtonTemplate.easyReplace({
						buttonId: opt.sContainer.php_htmlspecialchars() + '_button_' + iButtonRowIndex + '_' + iButtonIndex,
						buttonSrc: (is_sprite ? opt.sSprite : this[2]).php_htmlspecialchars(),
						posX: is_sprite ? this[2][0] : 0,
						posY: is_sprite ? this[2][1] + 2 : 2,
						buttonDescription: this[6].php_htmlspecialchars()
					});

					bPreviousWasDivider = false;
				}
			}
			// this[0] = sType, 1 = sName, 2 = options
			else if (this[0] == 'select')
			{
				var sOptions = '', sSelectValue, optname = '%opt%';

				// Fighting JavaScript's idea of order in a for loop... :P
				if ('' in this[2])
					sOptions = '<option data-hide>' + this[2][''].php_htmlspecialchars() + '</option>';
				for (sSelectValue in this[2])
				{
					// we've been through this before
					if (this[1] == 'sel_face')
						optname = '<span style="font-family: %opt%">%opt%</span>';
					else if (this[1] == 'sel_size')
						optname = '<span style="font-size: %opt%">%opt%</span>';
					else if (this[1] == 'sel_color')
						optname = '<span style="color: %val%">&diams;</span> %opt%';
					if (sSelectValue != '')
						sOptions += '<option value="' + sSelectValue.php_htmlspecialchars() + '">' + optname.replace(/%val%/g, sSelectValue).replace(/%opt%/g, this[2][sSelectValue]).php_htmlspecialchars() + '</option>';
				}

				sRowContent += opt.sSelectTemplate.easyReplace({
					selectName: this[1],
					selectId: opt.sContainer.php_htmlspecialchars() + '_select_' + iButtonRowIndex + '_' + iButtonIndex,
					selectOptions: sOptions
				});

				bPreviousWasDivider = false;
			}
			else
			{
				if (!bPreviousWasDivider)
					sRowContent += opt.sDividerTemplate;

				bPreviousWasDivider = true;
			}
		});

		sBbcContent += opt.sButtonRowTemplate.easyReplace({
			buttonRow: sRowContent
		});
	});

	$('#' + opt.sContainer).html(sBbcContent).find('select').sb();

	var that = this;
	$.each(opt.aButtonRows, function (iButtonRowIndex)
	{
		$.each(this, function (iButtonIndex)
		{
			if (this[0] == 'button')
			{
				if (!this[1])
					return;

				this.oImg = document.getElementById(opt.sContainer.php_htmlspecialchars() + '_button_' + iButtonRowIndex + '_' + iButtonIndex);
				this.oImg.style.cursor = 'pointer';
				if (opt.sButtonBackgroundPos)
				{
					this.oImg.style.background = 'url(' + opt.sSprite + ') no-repeat';
					this.oImg.style.backgroundPosition = '-' + opt.sButtonBackgroundPos[0] + 'px -' + opt.sButtonBackgroundPos[1] + 'px';
				}

				this.oImg.bHover = false;
				this.oImg.bIsActive = false;
				this.oImg.instanceRef = that;
				$(this.oImg)
					.mouseover(function () { this.instanceRef.handleButtonMouseOver(this); })
					.mouseout(function () { this.instanceRef.handleButtonMouseOut(this); })
					.click(function () { this.instanceRef.handleButtonClick(this); });
			}
			else if (this[0] == 'select')
			{
				this.oSelect = document.getElementById(opt.sContainer.php_htmlspecialchars() + '_select_' + iButtonRowIndex + '_' + iButtonIndex);

				this.oSelect.instanceRef = that;
				this.oSelect.onchange = this.onchange = function () {
					this.instanceRef.handleSelectChange(this);
				};
			}
		});
	});
}

weButtonBox.prototype.handleButtonMouseOver = function (oButtonImg)
{
	oButtonImg.bHover = true;
	this.updateButtonStatus(oButtonImg);
};

weButtonBox.prototype.handleButtonMouseOut = function (oButtonImg)
{
	oButtonImg.bHover = false;
	this.updateButtonStatus(oButtonImg);
};

weButtonBox.prototype.updateButtonStatus = function (oButtonImg)
{
	var sNewPos = 0;
	if (oButtonImg.bHover && oButtonImg.bIsActive && this.opt.sActiveButtonBackgroundPosHover)
		sNewPos = this.opt.sActiveButtonBackgroundPosHover;
	else if (!oButtonImg.bHover && oButtonImg.bIsActive && this.opt.sActiveButtonBackgroundPos)
		sNewPos = this.opt.sActiveButtonBackgroundPos;
	else if (oButtonImg.bHover && this.opt.sButtonBackgroundPosHover)
		sNewPos = this.opt.sButtonBackgroundPosHover;
	else if (this.opt.sButtonBackgroundPos)
		sNewPos = this.opt.sButtonBackgroundPos;

	if (oButtonImg.style.backgroundPosition != sNewPos && sNewPos)
		oButtonImg.style.backgroundPosition = '-' + sNewPos[0] + 'px -' + sNewPos[1] + 'px';
};

weButtonBox.prototype.handleButtonClick = function (oButtonImg)
{
	// Dissect the id attribute...
	var aMatches = oButtonImg.id.match(/(\d+)_(\d+)$/);
	if (aMatches.length != 3)
		return false;

	// ...so that we can point to the exact button.
	var oProperties = this.opt.aButtonRows[aMatches[1]][aMatches[2]];
	oProperties.bIsActive = oButtonImg.bIsActive;

	if (this.opt.sButtonClickHandler)
		this.opt.sButtonClickHandler(oProperties);

	return false;
};

weButtonBox.prototype.handleSelectChange = function (oSelectControl)
{
	// Dissect the id attribute...
	var aMatches = oSelectControl.id.match(/(\d+)_(\d+)$/);
	if (aMatches.length != 3)
		return false;

	// ...so that we can point to the exact button.
	if (this.opt.sSelectChangeHandler)
		this.opt.sSelectChangeHandler(this.opt.aButtonRows[aMatches[1]][aMatches[2]]);

	return true;
};

weButtonBox.prototype.setActive = function (aButtons)
{
	var that = this;
	$.each(this.opt.aButtonRows, function () {
		$.each(this, function () {
			if (this[0] == 'button' && this[1])
			{
				this.oImg.bIsActive = in_array(this[3], aButtons);
				that.updateButtonStatus(this.oImg);
			}
		});
	});
};

weButtonBox.prototype.emulateClick = function (sCode)
{
	var that = this;
	$.each(this.opt.aButtonRows, function () {
		$.each(this, function () {
			if (this[0] == 'button' && this[3] == sCode)
			{
				that.opt.sButtonClickHandler(this);
				return;
			}
		});
	});
	return false;
};

weButtonBox.prototype.setSelect = function (sSelectName, sValue)
{
	if (!this.opt.sButtonClickHandler)
		return;

	$.each(this.opt.aButtonRows, function () {
		$.each(this, function () {
			if (this[0] != 'select' || this[1] != sSelectName || $(this.oSelect).val() === sValue)
				return;
			$(this.oSelect).val(sValue);
			if (this.oSelect.selectedIndex < 0)
				this.oSelect.selectedIndex = 0;
			$(this.oSelect).sb();
		});
	});
};

/*
	Attachment selector, originally based on http://the-stickman.com/web-development/javascript/upload-multiple-files-with-a-single-file-element/
	The original code is MIT licensed, as discussed on http://the-stickman.com/using-code-from-this-site-ie-licence/
	This is quite heavily rewritten, though, to suit our purposes.
*/

function wedgeAttachSelect(opt)
{
	this.count = 0;
	this.attachId = 0;
	this.max = opt.max ? opt.max : -1;

	// Yay for scope issues.
	this.checkExtension = function (filename)
	{
		if (!opt.attachment_ext)
			return true; // We're not checking

		var dot = filename.lastIndexOf('.');
		if (!filename || filename.length == 0 || dot == -1)
		{
			opt.message_ext_error_final = opt.message_ext_error.replace(' ({ext})', '');
			return false; // Pfft, didn't specify anything, or no extension
		}

		var extension = (filename.substr(dot + 1, filename.length)).toLowerCase();
		if (!in_array(extension, opt.attachment_ext))
		{
			opt.message_ext_error_final = opt.message_ext_error.replace('{ext}', extension);
			return false;
		}

		return true;
	};

	this.checkActive = function ()
	{
		var session_attach = 0;
		$('input[type=checkbox]').each(function () {
			if (this.name == 'attach_del[]' && this.checked == true)
				session_attach++;
		});

		this.current_element.disabled = !(this.max == -1 || (this.max >= (session_attach + this.count)));
	};

	this.selectorHandler = function (event)
	{
		var element = event.target;

		if ($(element).val() === '')
			return false;

		// We've got one!! Check it, bag it.
		if (that.checkExtension(element.value))
		{
			// Hide this input.
			$(element).css({ position: 'absolute', left: -1000 });

			// Add a new file selector.
			that.createFileSelector();

			// Add the display entry and remove button.
			var new_row = document.createElement('div');
			new_row.element = element;
			new_row.innerHTML = '&nbsp; &nbsp;' + element.value;

			$('<input type="button" class="delete" style="margin-top: 4px" value="' + opt.message_txt_delete + '" />').click(function () {
				// Remove element from form
				this.parentNode.element.parentNode.removeChild(this.parentNode.element);
				this.parentNode.parentNode.removeChild(this.parentNode);
				this.parentNode.element.multi_selector.count--;
				that.checkActive();
				return false;
			}).prependTo(new_row);

			$('#' + opt.file_container).append(new_row);

			that.count++;
			that.current_element = element;
			that.checkActive();
		}
		else
		// Uh oh.
		{
			alert(opt.message_ext_error_final);
			that.createFileSelector();
			$(element).remove();
		}
	};

	this.prepareFileSelector = function (element)
	{
		if (element.tagName != 'INPUT' || element.type != 'file')
			return;

		$(element).attr({
			id: 'file_' + this.attachId++,
			name: 'attachment[]'
		});
		element.multi_selector = this;
		$(element).bind('change', function (event) { that.selectorHandler(event); });
	};

	this.createFileSelector = function ()
	{
		var new_element = $('<input type="file">').prependTo('#' + opt.file_container);
		this.current_element = new_element[0];
		this.prepareFileSelector(new_element[0]);
	};

	// And finally, we begin.
	var that = this;
	that.prepareFileSelector($('#' + opt.file_item)[0]);
};

/*
	Handles auto-saving of posts.
*/

function wedge_autoDraft(opt)
{
	this.opt = opt;
	this.opt.needsUpdate = false;

	var that = this;
	if (opt.iFreq > 0)
		this.opt.timer = setInterval(function () { that.draftSend.call(that); }, opt.iFreq);
}

wedge_autoDraft.prototype.needsUpdate = function (update)
{
	this.opt.needsUpdate = update;
};

wedge_autoDraft.prototype.draftSend = function ()
{
	if (!this.opt.needsUpdate)
		return;

	this.opt.needsUpdate = false;

	var
		sUrl = $('#' + this.opt.sForm).attr('action'),
		draftInfo = {
			draft: 'draft',
			draft_id: $('#draft_id').val(),
			subject: $('#' + this.opt.sForm + ' input[name="subject"]').val(),
			message: $('#' + this.opt.sEditor).val(),
			message_mode: $('#' + this.opt.sEditor + '_mode').val()
		},
		that = this,
		lastSavedDiv = this.opt.sLastNote;

	if (draftInfo.message === '')
		return;

	// We need to indicate that we're calling this to request XML.
	sUrl += (sUrl.indexOf('?') > 0 ? ';' : '?') + 'xml';

	// We're doing the whole WYSIWYG thing, but just for fun, we need to extract the object's frame
	if (draftInfo.message_mode == 1)
		draftInfo.message = $('#html_' + this.opt.sEditor).html();

	// This isn't nice either, but nicer than the above, sorry.
	draftInfo[we_sessvar] = we_sessid;

	// Depending on what we're doing, there might be other things we need to save, like topic details or PM recipients.
	if (this.opt.sType == 'auto_post')
	{
		draftInfo.topic = $('#' + this.opt.sForm + ' input[name="topic"]').val();
		draftInfo.icon = $('#' + this.opt.sForm + ' input[name="icon"]').val();
	}
	else if (this.opt.sType == 'auto_pm')
	{
		// Since we're here, we only need to bother with the JS, since the auto suggest will be available and will have already sorted out user ids.
		// This is not nice, though.
		var recipients = [];
		$('#' + this.opt.sForm + ' input[name="recipient_to\\[\\]"]').each(function () { recipients.push($(this).val()); });
		if (recipients.length)
			draftInfo['recipient_to[]'] = recipients;

		recipients = [];
		$('#' + this.opt.sForm + ' input[name="recipient_bcc\\[\\]"]').each(function () { recipients.push($(this).val()); });
		if (recipients.length)
			draftInfo['recipient_bcc[]'] = recipients;
	}

	$.post(sUrl, draftInfo, function (data)
	{
		$('#remove_draft').unbind('click'); // Just in case bad stuff happens.

		var
			obj = $('#lastsave', data),
			draft_id = obj.attr('draft'),
			url = obj.attr('url').replace(/DraftId/, draft_id).replace(/SessVar/, we_sessvar).replace(/SessId/, we_sessid);

		$('#draft_id').val(draft_id);
		$('#' + lastSavedDiv).html(obj.text() + ' &nbsp; ').append($('<input type="button" id="remove_draft" class="delete">').val(that.opt.sRemove));
		$('#remove_draft').click(function () {
			$.get(url, function () {
				$('#' + lastSavedDiv).empty();
				$('#draft_id').val('0');
			});
			clearInterval(that.opt.timer);
			return false;
		});
	});
};
