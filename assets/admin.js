(function () {
  if (!window.wp || !window.gitSwitcherData) {
    return;
  }

  const el = window.wp.element.createElement;
  const render = window.wp.element.render;
  const useState = window.wp.element.useState;
  const useEffect = window.wp.element.useEffect;
  const useRef = window.wp.element.useRef;
  const components = window.wp.components;

  const Popover = components.Popover;
  const Button = components.Button;
  const Badge = components.Badge;
  const Spinner = components.Spinner;
  const Notice = components.Notice;
  const TabPanel = components.TabPanel;
  const TextControl = components.TextControl;

  const i18n = gitSwitcherData.i18n || {};

  function postAjax(action, payload) {
    const params = new URLSearchParams();
    params.append("action", action);
    params.append("nonce", gitSwitcherData.nonce);

    Object.keys(payload || {}).forEach(function (key) {
      params.append(key, payload[key]);
    });

    return window
      .fetch(gitSwitcherData.ajaxUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: params.toString(),
      })
      .then(function (response) {
        return response.json();
      })
      .then(function (data) {
        if (!data || !data.success) {
          const message =
            data && data.data && data.data.message
              ? data.data.message
              : "Request failed";
          throw new Error(message);
        }
        return data.data;
      });
  }

  function GitSwitcherApp() {
    const [isOpen, setIsOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [repositories, setRepositories] = useState([]);
    const [expanded, setExpanded] = useState({});
    const [errorMsg, setErrorMsg] = useState("");
    const [switchingKey, setSwitchingKey] = useState("");
    const [gitBinary, setGitBinary] = useState(gitSwitcherData.gitBinary || "");
    const [savingSettings, setSavingSettings] = useState(false);
    const lastToggleRef = useRef(0);
    const [statAnchor, setStatAnchor] = useState(null);
    const [statContent, setStatContent] = useState("");
    const [showStat, setShowStat] = useState(false);

    const timeoutRef = useRef(null);

    function showError(message) {
      setErrorMsg(message);
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
      }
      timeoutRef.current = setTimeout(function () {
        setErrorMsg("");
        timeoutRef.current = null;
      }, 5000);
    }

    function normalizeBranch(s) {
      if (!s) {
        return "";
      }
      return String(s)
        .replace(/^refs\/heads\//, "")
        .trim()
        .toLowerCase();
    }

    function formatRelative(ts) {
      const now = Math.floor(Date.now() / 1000);
      let diff = now - Number(ts);
      if (isNaN(diff) || diff < 0) {
        return "";
      }
      if (diff < 60) {
        return diff + "s";
      }
      if (diff < 3600) {
        return Math.floor(diff / 60) + "m";
      }
      if (diff < 86400) {
        return Math.floor(diff / 3600) + "h";
      }
      if (diff < 604800) {
        return Math.floor(diff / 86400) + "d";
      }
      return Math.floor(diff / 604800) + "w";
    }

    function renderShowStatContent(content) {
      const text = String(content || "");
      const lines = text.split("\n");

      function renderSummaryLine(line, idx) {
        const tokenRegex = /(\d+\s+insertions?\(\+\))|(\d+\s+deletions?\(-\))/g;
        const parts = [];
        let cursor = 0;
        let match;

        while ((match = tokenRegex.exec(line)) !== null) {
          if (match.index > cursor) {
            parts.push(line.slice(cursor, match.index));
          }

          const tokenText = match[0];
          const tokenClass =
            tokenText.indexOf("insert") !== -1
              ? "git-switcher-showstat-token is-insertions"
              : "git-switcher-showstat-token is-deletions";

          parts.push(
            el(
              "span",
              {
                className: tokenClass,
                key: "showstat-token-" + idx + "-" + match.index,
              },
              tokenText,
            ),
          );

          cursor = match.index + tokenText.length;
        }

        if (cursor < line.length) {
          parts.push(line.slice(cursor));
        }

        return parts.length ? parts : line;
      }

      return lines.map(function (line, idx) {
        let className = "git-switcher-showstat-line";
        let lineContent = line;

        if (/^\s*\d+\s+files?\s+changed\b/.test(line)) {
          className += " is-summary";
          lineContent = renderSummaryLine(line, idx);
        } else if (/^\s*[^\s].*\|\s+\d+/.test(line)) {
          className += " is-file";
        } else if (/^(commit|Author:|Date:)\b/.test(line)) {
          className += " is-meta";
        }

        return el(
          "span",
          {
            className: className,
            key: "showstat-line-" + idx,
          },
          lineContent,
          idx < lines.length - 1 ? "\n" : "",
        );
      });
    }

    function loadRepositories() {
      setLoading(true);
      return postAjax("git_switcher_fetch_repositories", {})
        .then(function (data) {
          setRepositories(data.repositories || []);
        })
        .catch(function (err) {
          setErrorMsg(err.message);
        })
        .finally(function () {
          setLoading(false);
        });
    }

    function toggleRepo(slug) {
      setExpanded(function (prev) {
        const next = Object.assign({}, prev);
        next[slug] = !next[slug];
        return next;
      });
    }

    function switchBranch(repoSlug, branch) {
      const key = repoSlug + ":" + branch;
      setSwitchingKey(key);
      setErrorMsg("");

      postAjax("git_switcher_checkout_branch", {
        repo: repoSlug,
        branch: branch,
      })
        .then(function () {
          // assume success; refresh repository list
          return loadRepositories();
        })
        .catch(function (err) {
          setErrorMsg(err.message);
        })
        .finally(function () {
          setSwitchingKey("");
        });
    }

    function saveSettings() {
      setSavingSettings(true);
      setErrorMsg("");

      postAjax("git_switcher_save_settings", {
        git_binary: gitBinary,
      })
        .then(function (data) {
          // assume success
        })
        .catch(function (err) {
          setErrorMsg(err.message);
        })
        .finally(function () {
          setSavingSettings(false);
        });
    }

    useEffect(function () {
      const target = document.querySelector(
        "#wp-admin-bar-git-switcher > .ab-item",
      );
      if (!target) {
        return;
      }

      const clickHandler = function (event) {
        event.preventDefault();
        event.stopPropagation();
        setIsOpen(function (prev) {
          const next = !prev;
          const now = Date.now();
          // prevent immediate re-open race
          if (next) {
            if (now - lastToggleRef.current < 250) {
              return prev;
            }
            lastToggleRef.current = now;
            loadRepositories();
          } else {
            lastToggleRef.current = now;
          }
          return next;
        });
      };

      target.addEventListener("click", clickHandler);
      return function () {
        target.removeEventListener("click", clickHandler);
      };
    }, []);

    useEffect(function () {
      return function () {
        if (timeoutRef.current) {
          clearTimeout(timeoutRef.current);
        }
      };
    }, []);

    const anchor = document.getElementById("wp-admin-bar-git-switcher");
    if (!isOpen || !anchor) {
      return null;
    }

    return el(
      Popover,
      {
        anchor: anchor,
        placement: "bottom-start",
        onClose: function () {
          lastToggleRef.current = Date.now();
          setIsOpen(false);
        },
      },
      el(
        "div",
        { className: "git-switcher-popover" },
        // Render snackbar for errors only
        errorMsg
          ? el("div", { className: "git-switcher-snackbar" }, errorMsg)
          : null,
        el(
          TabPanel,
          {
            className: "git-switcher-tabs",
            tabs: [
              { name: "repos", title: i18n.tabPlugins || "Plugins" },
              { name: "settings", title: i18n.tabSettings || "Settings" },
            ],
          },
          function (tab) {
            if (tab.name === "settings") {
              return el(
                "div",
                { className: "git-switcher-settings" },
                el(TextControl, {
                  label: i18n.gitBinaryLabel || "Git binary path",
                  value: gitBinary,
                  onChange: function (value) {
                    setGitBinary(value);
                  },
                }),
                el("p", { className: "description" }, i18n.manageHint || ""),
                el(
                  Button,
                  {
                    variant: "primary",
                    onClick: saveSettings,
                    disabled: savingSettings,
                  },
                  savingSettings
                    ? i18n.switching || "Saving..."
                    : i18n.saveSettings || "Save settings",
                ),
              );
            }

            if (loading) {
              return el(
                "div",
                { className: "git-switcher-loading" },
                el(Spinner, {}),
                " ",
                i18n.loading || "Loading repositories...",
              );
            }

            if (!repositories.length) {
              return el(
                "p",
                { className: "git-switcher-empty" },
                i18n.noRepositories || "No git repositories found.",
              );
            }

            return el(
              "div",
              { className: "git-switcher-repo-list" },
              repositories.map(function (repo) {
                const isExpanded = !!expanded[repo.slug];

                return el(
                  "div",
                  { className: "git-switcher-repo-item", key: repo.slug },
                  el(
                    "button",
                    {
                      type: "button",
                      className: "git-switcher-repo-toggle",
                      onClick: function () {
                        toggleRepo(repo.slug);
                      },
                    },
                    el(
                      "span",
                      { className: "git-switcher-folder" },
                      repo.folder,
                    ),
                    el(
                      "span",
                      { className: "git-switcher-branch-meta" },
                      repo.branch || "-",
                    ),
                  ),
                  isExpanded
                    ? el(
                        "ul",
                        { className: "git-switcher-branches" },
                        (repo.branches || []).map(function (branchObj) {
                          const branch = branchObj.name;
                          const lastTs = branchObj.last_commit;
                          const lastAuthor = branchObj.last_author || "";
                          const lastShow = branchObj.last_commit_show || "";
                          const ahead = branchObj.ahead || 0;
                          const behind = branchObj.behind || 0;
                          const upstream = branchObj.upstream || "";
                          const hasUpstream = !!upstream;
                          const hasDivergence =
                            hasUpstream && (ahead || behind);
                          const lastShowShort = lastShow
                            ? String(lastShow).split("\n")[0]
                            : "";
                          const tooltipParts = [];
                          if (lastShowShort) {
                            tooltipParts.push(lastShowShort);
                          }
                          if (upstream) {
                            tooltipParts.push("Upstream: " + upstream);
                          }
                          const tooltip = tooltipParts.length
                            ? tooltipParts.join("\n")
                            : undefined;
                          const isCurrent =
                            normalizeBranch(branch) ===
                            normalizeBranch(repo.branch);
                          const key = repo.slug + ":" + branch;
                          return el(
                            "li",
                            { key: key },
                            el(
                              "button",
                              {
                                type: "button",
                                className:
                                  "git-switcher-branch-btn" +
                                  (isCurrent ? " is-current" : ""),
                                onClick: function () {
                                  switchBranch(repo.slug, branch);
                                },
                                disabled: switchingKey === key,
                                title: tooltip,
                                onMouseEnter: function (e) {
                                  if (lastShow) {
                                    setStatAnchor(e.currentTarget);
                                    setStatContent(lastShow);
                                    setShowStat(true);
                                  }
                                },
                                onMouseLeave: function () {
                                  setShowStat(false);
                                },
                              },
                              el(
                                "span",
                                { className: "git-switcher-branch-left" },
                                isCurrent ? "✓ " : "",
                                branch,
                                hasDivergence && ahead
                                  ? el(
                                      Badge,
                                      {
                                        className:
                                          "git-switcher-badge git-switcher-ahead",
                                        title: "Upstream: " + upstream,
                                        status: "success",
                                        key: "ahead-" + key,
                                      },
                                      " ↑" + ahead,
                                    )
                                  : null,
                                hasDivergence && behind
                                  ? el(
                                      Badge,
                                      {
                                        className:
                                          "git-switcher-badge git-switcher-behind",
                                        title: "Upstream: " + upstream,
                                        status: "warning",
                                        key: "behind-" + key,
                                      },
                                      " ↓" + behind,
                                    )
                                  : null,
                              ),
                              el(
                                "span",
                                {
                                  className: "git-switcher-time",
                                  title: lastTs
                                    ? new Date(
                                        Number(lastTs) * 1000,
                                      ).toLocaleString()
                                    : "",
                                },
                                lastTs ? formatRelative(lastTs) : "",
                                lastAuthor
                                  ? el(
                                      "span",
                                      { className: "git-switcher-author" },
                                      " · " + lastAuthor,
                                    )
                                  : null,
                              ),
                              switchingKey === key
                                ? el(
                                    "span",
                                    { className: "git-switcher-spinner" },
                                    el(Spinner, {}),
                                  )
                                : null,
                            ),
                            showStat && statAnchor
                              ? el(
                                  Popover,
                                  {
                                    anchor: statAnchor,
                                    placement: "right-start",
                                    className: "git-switcher-showstat-popover",
                                    onClose: function () {
                                      setShowStat(false);
                                    },
                                  },
                                  el(
                                    "div",
                                    { className: "git-switcher-showstat" },
                                    el(
                                      "pre",
                                      null,
                                      renderShowStatContent(statContent),
                                    ),
                                  ),
                                )
                              : null,
                          );
                        }),
                      )
                    : null,
                );
              }),
            );
          },
        ),
      ),
    );
  }

  function mount() {
    const appRoot = document.createElement("div");
    appRoot.id = "git-switcher-app";
    document.body.appendChild(appRoot);
    render(el(GitSwitcherApp), appRoot);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mount);
  } else {
    mount();
  }
})();
