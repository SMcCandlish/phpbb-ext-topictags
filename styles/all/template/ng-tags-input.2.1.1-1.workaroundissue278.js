(function () {
    "use strict";
    function r() {
        var e = {};
        return {
            on: function (t, n) {
                t.split(" ").forEach(function (t) {
                    if (!e[t]) {
                        e[t] = [];
                    }
                    e[t].push(n);
                });
                return this;
            },
            trigger: function (t, n) {
                angular.forEach(e[t], function (e) {
                    e.call(null, n);
                });
                return this;
            },
        };
    }
    function i(e, t) {
        e = e || [];
        if (e.length > 0 && !angular.isObject(e[0])) {
            e.forEach(function (n, r) {
                e[r] = {};
                e[r][t] = n;
            });
        }
        return e;
    }
    function s(e, t, n) {
        var r = null;
        for (var i = 0; i < e.length; i++) {
            if (u(e[i][n]).toLowerCase() === u(t[n]).toLowerCase()) {
                r = e[i];
                break;
            }
        }
        return r;
    }
    function o(e, t, n) {
        if (!t) {
            return e;
        }
        var r = t.replace(/([.?*+^$[\]\\(){}|-])/g, "\\$1");
        return e.replace(new RegExp(r, "gi"), n);
    }
    function u(e) {
        return angular.isUndefined(e) || e == null ? "" : e.toString().trim();
    }
    function a(e) {
        return e.replace(/&/g, "&").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    }
    var e = { backspace: 8, tab: 9, enter: 13, escape: 27, space: 32, up: 38, down: 40, comma: 188 };
    var t = 9007199254740991;
    var n = ["text", "email", "url"];
    var f = angular.module("ngTagsInput", []);
    f.directive("tagsInput", [
        "$timeout",
        "$document",
        "tagsInputConfig",
        function (o, a, f) {
            function l(e, t) {
                var n = {},
                    r,
                    i,
                    o;
                r = function (t) {
                    return u(t[e.displayProperty]);
                };
                i = function (t, n) {
                    t[e.displayProperty] = n;
                };
                o = function (t) {
                    var i = r(t);
                    return i && i.length >= e.minLength && i.length <= e.maxLength && e.allowedTagsPattern.test(i) && !s(n.items, t, e.displayProperty);
                };
                n.items = [];
                n.addText = function (e) {
                    var t = {};
                    i(t, e);
                    return n.add(t);
                };
                n.add = function (s) {
                    var u = r(s);
                    if (e.replaceSpacesWithDashes) {
                        u = u.replace(/\s/g, "-");
                    }
                    i(s, u);
                    if (o(s)) {
                        n.items.push(s);
                        t.trigger("tag-added", { $tag: s });
                    } else if (u) {
                        t.trigger("invalid-tag", { $tag: s });
                    }
                    return s;
                };
                n.remove = function (e) {
                    var r = n.items.splice(e, 1)[0];
                    t.trigger("tag-removed", { $tag: r });
                    return r;
                };
                n.removeLast = function () {
                    var t,
                        r = n.items.length - 1;
                    if (e.enableEditingLastTag || n.selected) {
                        n.selected = null;
                        t = n.remove(r);
                    } else if (!n.selected) {
                        n.selected = n.items[r];
                    }
                    return t;
                };
                return n;
            }
            function c(e) {
                return n.indexOf(e) !== -1;
            }
            return {
                restrict: "E",
                require: "ngModel",
                scope: { tags: "=ngModel", onTagAdded: "&", onTagRemoved: "&" },
                replace: false,
                transclude: true,
                templateUrl: "ngTagsInput/tags-input.html",
                controller: [
                    "$scope",
                    "$attrs",
                    "$element",
                    function (e, n, i) {
                        e.events = new r();
                        f.load("tagsInput", e, n, {
                            type: [String, "text", c],
                            placeholder: [String, "Add a tag"],
                            tabindex: [Number, null],
                            removeTagSymbol: [String, String.fromCharCode(215)],
                            replaceSpacesWithDashes: [Boolean, true],
                            minLength: [Number, 3],
                            maxLength: [Number, t],
                            addOnEnter: [Boolean, true],
                            addOnSpace: [Boolean, false],
                            addOnComma: [Boolean, true],
                            addOnBlur: [Boolean, true],
                            allowedTagsPattern: [RegExp, /.+/],
                            enableEditingLastTag: [Boolean, false],
                            minTags: [Number, 0],
                            maxTags: [Number, t],
                            displayProperty: [String, "text"],
                            allowLeftoverText: [Boolean, false],
                            addFromAutocompleteOnly: [Boolean, false],
                        });
                        e.tagList = new l(e.options, e.events);
                        this.registerAutocomplete = function () {
                            var t = i.find("input");
                            t.on("keydown", function (t) {
                                e.events.trigger("input-keydown", t);
                            });
                            return {
                                addTag: function (t) {
                                    return e.tagList.add(t);
                                },
                                focusInput: function () {
                                    t[0].focus();
                                },
                                getTags: function () {
                                    return e.tags;
                                },
                                getCurrentTagText: function () {
                                    return e.newTag.text;
                                },
                                getOptions: function () {
                                    return e.options;
                                },
                                on: function (t, n) {
                                    e.events.on(t, n);
                                    return this;
                                },
                            };
                        };
                    },
                ],
                link: function (t, n, r, s) {
                    var f = [e.enter, e.comma, e.space, e.backspace],
                        l = t.tagList,
                        c = t.events,
                        h = t.options,
                        p = n.find("input"),
                        d = ["minTags", "maxTags", "allowLeftoverText"],
                        v;
                    v = function () {
                        s.$setValidity("maxTags", t.tags.length <= h.maxTags);
                        s.$setValidity("minTags", t.tags.length >= h.minTags);
                        s.$setValidity("leftoverText", h.allowLeftoverText ? true : !t.newTag.text);
                    };
                    c.on("tag-added", t.onTagAdded)
                        .on("tag-removed", t.onTagRemoved)
                        .on("tag-added", function () {
                            t.newTag.text = "";
                        })
                        .on("tag-added tag-removed", function () {
                            s.$setViewValue(t.tags);
                        })
                        .on("invalid-tag", function () {
                            t.newTag.invalid = true;
                        })
                        .on("input-change", function () {
                            l.selected = null;
                            t.newTag.invalid = null;
                        })
                        .on("input-focus", function () {
                            s.$setValidity("leftoverText", true);
                        })
                        .on("input-blur", function () {
                            if (!h.addFromAutocompleteOnly) {
                                if (h.addOnBlur) {
                                    l.addText(t.newTag.text);
                                }
                                v();
                            }
                        })
                        .on("option-change", function (e) {
                            if (d.indexOf(e.name) !== -1) {
                                v();
                            }
                        });
                    t.newTag = { text: "", invalid: null };
                    t.getDisplayText = function (e) {
                        return u(e[h.displayProperty]);
                    };
                    t.track = function (e) {
                        return e[h.displayProperty];
                    };
                    t.newTagChange = function () {
                        c.trigger("input-change", t.newTag.text);
                    };
                    t.$watch("tags", function (e) {
                        t.tags = i(e, h.displayProperty);
                        l.items = t.tags;
                    });
                    t.$watch("tags.length", function () {
                        v();
                    });
                    p.on("keydown", function (n) {
                        if (n.isImmediatePropagationStopped && n.isImmediatePropagationStopped()) {
                            return;
                        }
                        var r = n.keyCode,
                            i = n.shiftKey || n.altKey || n.ctrlKey || n.metaKey,
                            s = {},
                            o,
                            u;
                        if (i || f.indexOf(r) === -1) {
                            return;
                        }
                        s[e.enter] = h.addOnEnter;
                        s[e.comma] = h.addOnComma;
                        s[e.space] = h.addOnSpace;
                        o = !h.addFromAutocompleteOnly && s[r];
                        u = !o && r === e.backspace && t.newTag.text.length === 0;
                        if (o) {
                            l.addText(t.newTag.text);
                            t.$apply();
                            n.preventDefault();
                        } else if (u) {
                            var a = l.removeLast();
                            if (a && h.enableEditingLastTag) {
                                t.newTag.text = a[h.displayProperty];
                            }
                            t.$apply();
                            n.preventDefault();
                        }
                    })
                        .on("focus", function () {
                            if (t.hasFocus) {
                                return;
                            }
                            t.hasFocus = true;
                            c.trigger("input-focus");
                            t.$apply();
                        })
                        .on("blur", function () {
                            o(function () {
                                var e = a.prop("activeElement"),
                                    r = e === p[0],
                                    i = n[0].contains(e);
                                if (r || !i) {
                                    t.hasFocus = false;
                                    c.trigger("input-blur");
                                }
                            });
                        });
                    n.find("div").on("click", function () {
                        p[0].focus();
                    });
                },
            };
        },
    ]);
    f.directive("autoComplete", [
        "$document",
        "$timeout",
        "$sce",
        "tagsInputConfig",
        function (t, n, r, f) {
            function l(e, t) {
                var r = {},
                    o,
                    u,
                    a;
                u = function (e, n) {
                    return e.filter(function (e) {
                        return !s(n, e, t.tagsInput.displayProperty);
                    });
                };
                r.reset = function () {
                    a = null;
                    r.items = [];
                    r.visible = false;
                    r.index = -1;
                    r.selected = null;
                    r.query = null;
                    n.cancel(o);
                };
                r.show = function () {
                    r.selected = null;
                    r.visible = true;
                };
                r.load = function (s, f) {
                    n.cancel(o);
                    o = n(
                        function () {
                            r.query = s;
                            var n = e({ $query: s });
                            a = n;
                            n.then(function (e) {
                                if (n !== a) {
                                    return;
                                }
                                e = i(e.data || e, t.tagsInput.displayProperty);
                                e = u(e, f);
                                r.items = e.slice(0, t.maxResultsToShow);
                                if (r.items.length > 0) {
                                    r.show();
                                } else {
                                    r.reset();
                                }
                            });
                        },
                        t.debounceDelay,
                        false
                    );
                };
                r.selectNext = function () {
                    r.select(++r.index);
                };
                r.selectPrior = function () {
                    r.select(--r.index);
                };
                r.select = function (e) {
                    if (e < 0) {
                        e = r.items.length - 1;
                    } else if (e >= r.items.length) {
                        e = 0;
                    }
                    r.index = e;
                    r.selected = r.items[e];
                };
                r.reset();
                return r;
            }
            return {
                restrict: "E",
                require: "^tagsInput",
                scope: { source: "&" },
                templateUrl: "ngTagsInput/auto-complete.html",
                link: function (t, n, i, s) {
                    var c = [e.enter, e.tab, e.escape, e.up, e.down],
                        h,
                        p,
                        d,
                        v,
                        m,
                        g;
                    f.load("autoComplete", t, i, {
                        debounceDelay: [Number, 100],
                        minLength: [Number, 3],
                        highlightMatchedText: [Boolean, true],
                        maxResultsToShow: [Number, 10],
                        loadOnDownArrow: [Boolean, false],
                        loadOnEmpty: [Boolean, false],
                        loadOnFocus: [Boolean, false],
                    });
                    d = t.options;
                    p = s.registerAutocomplete();
                    d.tagsInput = p.getOptions();
                    h = new l(t.source, d);
                    v = function (e) {
                        return e[d.tagsInput.displayProperty];
                    };
                    m = function (e) {
                        return u(v(e));
                    };
                    g = function (e) {
                        return (e && e.length >= d.minLength) || (!e && d.loadOnEmpty);
                    };
                    t.suggestionList = h;
                    t.addSuggestionByIndex = function (e) {
                        h.select(e);
                        t.addSuggestion();
                    };
                    t.addSuggestion = function () {
                        var e = false;
                        if (h.selected) {
                            p.addTag(h.selected);
                            h.reset();
                            p.focusInput();
                            e = true;
                        }
                        return e;
                    };
                    t.highlight = function (e) {
                        var t = m(e);
                        t = a(t);
                        if (d.highlightMatchedText) {
                            t = o(t, a(h.query), "<em>$&</em>");
                        }
                        return r.trustAsHtml(t);
                    };
                    t.track = function (e) {
                        return v(e);
                    };
                    p.on("tag-added tag-removed invalid-tag input-blur", function () {
                        h.reset();
                    })
                        .on("input-change", function (e) {
                            if (g(e)) {
                                h.load(e, p.getTags());
                            } else {
                                h.reset();
                            }
                        })
                        .on("input-focus", function () {
                            var e = p.getCurrentTagText();
                            if (d.loadOnFocus && g(e)) {
                                h.load(e, p.getTags());
                            }
                        })
                        .on("input-keydown", function (n) {
                            var r = false;
                            n.stopImmediatePropagation = function () {
                                r = true;
                                n.stopPropagation();
                            };
                            n.isImmediatePropagationStopped = function () {
                                return r;
                            };
                            var i = n.keyCode,
                                s = false;
                            if (c.indexOf(i) === -1) {
                                return;
                            }
                            if (h.visible) {
                                if (i === e.down) {
                                    h.selectNext();
                                    s = true;
                                } else if (i === e.up) {
                                    h.selectPrior();
                                    s = true;
                                } else if (i === e.escape) {
                                    h.reset();
                                    s = true;
                                } else if (i === e.enter || i === e.tab) {
                                    s = t.addSuggestion();
                                }
                            } else {
                                if (i === e.down && t.options.loadOnDownArrow) {
                                    h.load(p.getCurrentTagText(), p.getTags());
                                    s = true;
                                }
                            }
                            if (s) {
                                n.preventDefault();
                                n.stopImmediatePropagation();
                                t.$apply();
                            }
                        });
                },
            };
        },
    ]);
    f.directive("tiTranscludeAppend", function () {
        return function (e, t, n, r, i) {
            i(function (e) {
                t.append(e);
            });
        };
    });
    f.directive("tiAutosize", [
        "tagsInputConfig",
        function (e) {
            return {
                restrict: "A",
                require: "ngModel",
                link: function (t, n, r, i) {
                    var s = e.getTextAutosizeThreshold(),
                        o,
                        u;
                    o = angular.element('<span class="input"></span>');
                    o.css("display", "none").css("visibility", "hidden").css("width", "auto").css("white-space", "pre");
                    n.parent().append(o);
                    u = function (e) {
                        var t = e,
                            i;
                        if (angular.isString(t) && t.length === 0) {
                            t = r.placeholder;
                        }
                        if (t) {
                            o.text(t);
                            o.css("display", "");
                            i = o.prop("offsetWidth");
                            o.css("display", "none");
                        }
                        n.css("width", i ? i + s + "px" : "");
                        return e;
                    };
                    i.$parsers.unshift(u);
                    i.$formatters.unshift(u);
                    r.$observe("placeholder", function (e) {
                        if (!i.$modelValue) {
                            u(e);
                        }
                    });
                },
            };
        },
    ]);
    f.directive("tiBindAttrs", function () {
        return function (e, t, n) {
            e.$watch(
                n.tiBindAttrs,
                function (e) {
                    angular.forEach(e, function (e, t) {
                        n.$set(t, e);
                    });
                },
                true
            );
        };
    });
    f.provider("tagsInputConfig", function () {
        var e = {},
            t = {},
            n = 3;
        this.setDefaults = function (t, n) {
            e[t] = n;
            return this;
        };
        this.setActiveInterpolation = function (e, n) {
            t[e] = n;
            return this;
        };
        this.setTextAutosizeThreshold = function (e) {
            n = e;
            return this;
        };
        this.$get = [
            "$interpolate",
            function (r) {
                var i = {};
                i[String] = function (e) {
                    return e;
                };
                i[Number] = function (e) {
                    return parseInt(e, 10);
                };
                i[Boolean] = function (e) {
                    return e.toLowerCase() === "true";
                };
                i[RegExp] = function (e) {
                    var t = e.lastIndexOf("/");
                    var n = e.substring(1, t);
                    var r = e.substring(t + 1, e.length);
                    return new RegExp(n, r);
                };
                return {
                    load: function (n, s, o, u) {
                        var a = function () {
                            return true;
                        };
                        s.options = {};
                        angular.forEach(u, function (u, f) {
                            var l, c, h, p, d, v;
                            l = u[0];
                            c = u[1];
                            h = u[2] || a;
                            p = i[l];
                            d = function () {
                                var t = e[n] && e[n][f];
                                return angular.isDefined(t) ? t : c;
                            };
                            v = function (e) {
                                s.options[f] = e && h(e) ? p(e) : d();
                            };
                            if (t[n] && t[n][f]) {
                                o.$observe(f, function (e) {
                                    v(e);
                                    s.events.trigger("option-change", { name: f, newValue: e });
                                });
                            } else {
                                v(o[f] && r(o[f])(s.$parent));
                            }
                        });
                    },
                    getTextAutosizeThreshold: function () {
                        return n;
                    },
                };
            },
        ];
    });
    f.run([
        "$templateCache",
        function (e) {
            e.put(
                "ngTagsInput/tags-input.html",
                '<div class="host" tabindex="-1" ti-transclude-append=""><div class="tags" ng-class="{focused: hasFocus}"><ul class="tag-list"><li class="tag-item" ng-repeat="tag in tagList.items track by track(tag)" ng-class="{ selected: tag == tagList.selected }"><span ng-bind="getDisplayText(tag)"></span> <a class="remove-button" ng-click="tagList.remove($index)" ng-bind="options.removeTagSymbol"></a></li></ul><input class="input" ng-model="newTag.text" ng-change="newTagChange()" ng-trim="false" ng-class="{\'invalid-tag\': newTag.invalid}" ti-bind-attrs="{type: options.type, placeholder: options.placeholder, tabindex: options.tabindex}" ti-autosize=""></div></div>'
            );
            e.put(
                "ngTagsInput/auto-complete.html",
                '<div class="autocomplete" ng-show="suggestionList.visible"><ul class="suggestion-list"><li class="suggestion-item" ng-repeat="item in suggestionList.items track by track(item)" ng-class="{selected: item == suggestionList.selected}" ng-click="addSuggestionByIndex($index)" ng-mouseenter="suggestionList.select($index)" ng-bind-html="highlight(item)"></li></ul></div>'
            );
        },
    ]);
})();
