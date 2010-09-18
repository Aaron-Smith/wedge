// The purpose of this code is to fix the height of overflow: auto blocks, because some browsers can't figure it out for themselves.
function smf_codeBoxFix()
{
	var codeFix = document.getElementsByTagName('code');
	for (var i = codeFix.length - 1; i >= 0; i--)
	{
		if (is_webkit && codeFix[i].offsetHeight < 20)
			codeFix[i].style.height = (codeFix[i].offsetHeight + 20) + 'px';

		else if (is_ff && (codeFix[i].scrollWidth > codeFix[i].clientWidth || codeFix[i].clientWidth == 0))
			codeFix[i].style.overflow = 'scroll';

		else if ('currentStyle' in codeFix[i] && codeFix[i].currentStyle.overflow == 'auto' && (codeFix[i].currentStyle.height == '' || codeFix[i].currentStyle.height == 'auto') && (codeFix[i].scrollWidth > codeFix[i].clientWidth || codeFix[i].clientWidth == 0) && (codeFix[i].offsetHeight != 0))
			codeFix[i].style.height = (codeFix[i].offsetHeight + 24) + 'px';
	}
}

// Add a fix for code stuff?
if (is_ie || is_webkit || is_ff)
	addLoadEvent(smf_codeBoxFix);

// Toggles the element height and width styles of an image.
function smc_toggleImageDimensions()
{
	var oImage, oImages = document.querySelectorAll ? document.querySelectorAll('img.resized') : document.getElementsByTagName('IMG');
	for (oImage in oImages)
	{
		// Not a resized image? Skip it.
		if (oImages[oImage].className == undefined || oImages[oImage].className.indexOf('bbc_img resized') == -1)
			continue;

		oImages[oImage].style.cursor = 'pointer';
		oImages[oImage].onclick = function() {
			this.style.width = this.style.height = this.style.width == 'auto' ? null : 'auto';
		};
	}
}

// Add a load event for the function above.
addLoadEvent(smc_toggleImageDimensions);

// Adds a button to a certain button strip.
function smf_addButton(sButtonStripId, bUseImage, oOptions)
{
	var oButtonStrip = document.getElementById(sButtonStripId);
	var aItems = oButtonStrip.getElementsByTagName('span');

	// Remove the 'last' class from the last item.
	if (aItems.length > 0)
	{
		var oLastSpan = aItems[aItems.length - 1];
		oLastSpan.className = oLastSpan.className.replace(/\s*last/, 'position_holder');
	}

	// Add the button.
	var oButtonStripList = oButtonStrip.getElementsByTagName('ul')[0];
	var oNewButton = document.createElement('li');
	oNewButton.innerHTML = '<a href="' + oOptions.sUrl + '" ' + ('sCustom' in oOptions ? oOptions.sCustom : '') + '><span class="last"' + ('sId' in oOptions ? ' id="' + oOptions.sId + '"': '') + '>' + oOptions.sText + '</span></a>';

	oButtonStripList.appendChild(oNewButton);
}

// Adds hover events to list items. Used for a versions of IE that don't support this by default.
var smf_addListItemHoverEvents = function()
{
	var cssRule, newSelector;

	// Add a rule for the list item hover event to every stylesheet.
	for (var iStyleSheet = 0; iStyleSheet < document.styleSheets.length; iStyleSheet++)
		for (var iRule = 0; iRule < document.styleSheets[iStyleSheet].rules.length; iRule++)
		{
			oCssRule = document.styleSheets[iStyleSheet].rules[iRule];
			if (oCssRule.selectorText.indexOf('LI:hover') != -1)
			{
				sNewSelector = oCssRule.selectorText.replace(/LI:hover/gi, 'LI.iehover');
				document.styleSheets[iStyleSheet].addRule(sNewSelector, oCssRule.style.cssText);
			}
		}

	// Now add handling for these hover events.
	var oListItems = document.getElementsByTagName('LI');
	for (oListItem in oListItems)
	{
		oListItems[oListItem].onmouseover = function() {
			this.className += ' iehover';
		};

		oListItems[oListItem].onmouseout = function() {
			this.className = this.className.replace(new RegExp(' iehover\\b'), '');
		};
	}
}

// If your browser doesn't support rounded corners, we can still emulate them.
function emulateRounded()
{
	// Import the emulation stylesheet...
	if (document.createStyleSheet)
		document.createStyleSheet(smf_theme_url + '/css/old.css');
	else
	{
		var old = document.createElement('LINK');
		old.rel = 'stylesheet';
		old.href = 'data:text/css,' + escape('@import url(' + smf_theme_url + '/css/old.css);');
		document.getElementsByTagName('head')[0].appendChild(old);
	}

	var divs = document.querySelectorAll ? document.querySelectorAll('div.wrc, div.rrc') : document.getElementsByTagName('DIV'), upperFrame, lowerFrame, i;
	for (i in divs)
	{
		var div = divs[i], cls = div.className ? div.className : '';
		if (cls.indexOf(' wrc') > -1)
			div.innerHTML = '<span class="topslice"><span></span></span>' + div.innerHTML + '<span class="botslice"><span></span></span>';
		else if (cls.indexOf(' rrc') > -1)
		{
			upperFrame = document.createElement('SPAN'); upperFrame.className = 'upperframe'; upperFrame.innerHTML = '<span></span>';
			lowerFrame = document.createElement('SPAN'); lowerFrame.className = 'lowerframe'; lowerFrame.innerHTML = '<span></span>';
			var par = div.parentNode;
			par.insertBefore(upperFrame, div);
			par.lastChild == div ? par.appendChild(lowerFrame) : par.insertBefore(lowerFrame, div.nextSibling);
		}
	}
}

if (!can_borderradius)
{
	if (document.addEventListener)
		document.addEventListener('DOMContentLoaded', emulateRounded, false);
	else // IE?
		addLoadEvent(emulateRounded);
}

// Add hover events to list items if the browser requires it.
if (is_ie7down)
	addLoadEvent(smf_addListItemHoverEvents);
