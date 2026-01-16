use std::sync::{Arc, Mutex};

use arc_swap::ArcSwap;
use libddwaf::{
    object::{WafMap, WafObject, WafOwnedDefaultAllocator},
    Builder, Config, Handle,
};

use crate::client::log::error;

/// A WAF instance that can be shared (through clone()) and updated by any thread.
///
/// This provides a thread-safe wrapper around libddwaf's `Builder` and `Handle`,
/// allowing multiple threads to use the same WAF instance while also supporting
/// runtime configuration updates.
pub struct UpdateableWafInstance {
    inner: Arc<UpdateableWafInstanceInner>,
}

struct UpdateableWafInstanceInner {
    prot_state: Mutex<ProtectedState>,
    waf_handle: ArcSwap<Handle>,
    initial_ruleset: WafObject,
}

struct ProtectedState {
    builder: Builder,
    initial_ruleset_added: bool,
}

impl UpdateableWafInstance {
    pub const INITIAL_RULESET: &'static str = "initial_ruleset";
    const OBFUSCATOR_KEY: &str = "datadog/0/ASM_DD/0/config";

    /// Creates a new `UpdateableWafInstance` with an initial ruleset.
    ///
    /// # Arguments
    /// * `ruleset` - The initial ruleset to load
    /// * `config` - Optional WAF configuration
    /// * `diagnostics` - Optional diagnostics output for ruleset loading errors/warnings
    ///
    /// # Errors
    /// Returns an error if the builder cannot be created or the initial ruleset
    /// cannot be added.
    pub fn new(
        ruleset: WafObject,
        config: Option<&Config>,
        diagnostics: Option<&mut WafOwnedDefaultAllocator<WafMap>>,
    ) -> anyhow::Result<Self> {
        let mut builder =
            Builder::new(config).ok_or_else(|| anyhow::anyhow!("Failed to create WAF builder"))?;

        if !builder.add_or_update_config(Self::INITIAL_RULESET, &ruleset, diagnostics) {
            return Err(anyhow::anyhow!(
                "Failed to add initial ruleset (add_or_update_config returned false)"
            ));
        }

        let waf = builder
            .build()
            .ok_or_else(|| anyhow::anyhow!("Failed to build WAF instance"))?;

        Ok(Self {
            inner: Arc::new(UpdateableWafInstanceInner {
                prot_state: Mutex::new(ProtectedState {
                    builder,
                    initial_ruleset_added: true,
                }),
                waf_handle: ArcSwap::from_pointee(waf),
                initial_ruleset: ruleset,
            }),
        })
    }

    /// Returns a reference to the current WAF handle.
    ///
    /// This can be used to create new contexts for processing requests.
    #[must_use]
    pub fn current(&self) -> Arc<Handle> {
        self.inner.waf_handle.load_full()
    }

    /// Adds or updates a configuration at the specified path.
    ///
    /// This does not automatically rebuild the WAF instance. Call [`Self::update`]
    /// to apply the changes.
    ///
    /// # Arguments
    /// * `path` - The logical path for this configuration
    /// * `ruleset` - The configuration/ruleset data
    /// * `diagnostics` - Optional diagnostics output
    ///
    /// # Returns
    /// `true` if the configuration was successfully added/updated, `false` otherwise.
    pub fn add_or_update_config(
        &self,
        path: &str,
        ruleset: &impl AsRef<libddwaf_sys::ddwaf_object>,
        diagnostics: Option<&mut WafOwnedDefaultAllocator<WafMap>>,
    ) -> bool {
        let mut guard = self.inner.prot_state.lock().unwrap();

        if guard.initial_ruleset_added && path.contains("/ASM_DD/") {
            guard.initial_ruleset_added = false;
            guard.builder.remove_config(Self::INITIAL_RULESET);
        }

        guard
            .builder
            .add_or_update_config(path, ruleset, diagnostics)
    }

    /// Removes a configuration at the specified path.
    ///
    /// This does not automatically rebuild the WAF instance. Call [`Self::update`]
    /// to apply the changes.
    ///
    /// # Returns
    /// `true` if a configuration was removed, `false` if no configuration existed at that path.
    ///
    /// # Note
    /// If the last ASM_DD config is removed, the initial ruleset will be added back.
    /// Consequently, when doing an update, first add the new ASM_DD config, and only
    /// then remove the old one.
    pub fn remove_config(&self, path: &str) -> bool {
        let mut guard = self.inner.prot_state.lock().unwrap();
        let removed = guard.builder.remove_config(path);
        if removed && path.contains("/ASM_DD/") && !guard.initial_ruleset_added {
            let has_other_asm_dd = Self::has_asm_dd_configs(&mut guard.builder);
            if !has_other_asm_dd {
                guard.initial_ruleset_added = true;
                let res = guard.builder.add_or_update_config(
                    Self::INITIAL_RULESET,
                    &self.inner.initial_ruleset,
                    None,
                );
                if res {
                    log::debug!("Restored initial ruleset after removing last ASM_DD config");
                } else {
                    error!("Failed to add initial ruleset after removing ASM_DD config");
                    return false;
                }
            }
        }
        removed
    }

    /// Returns the number of configuration paths currently loaded.
    ///
    /// # Arguments
    /// * `filter` - Optional regex filter to count only matching paths
    #[must_use]
    #[cfg(test)]
    pub fn config_paths_count(&self, filter: Option<&str>) -> u32 {
        let mut guard = self.inner.prot_state.lock().unwrap();
        guard.builder.config_paths_count(filter)
    }

    /// Returns the configuration paths currently loaded.
    ///
    /// # Arguments
    /// * `filter` - Optional regex filter to return only matching paths
    #[must_use]
    #[cfg(test)]
    pub fn config_paths(
        &self,
        filter: Option<&str>,
    ) -> WafOwnedDefaultAllocator<libddwaf::object::WafArray> {
        let mut guard = self.inner.prot_state.lock().unwrap();
        guard.builder.config_paths(filter)
    }

    fn has_asm_dd_configs(builder: &mut Builder) -> bool {
        let paths = builder.config_paths(Some("/ASM_DD/"));
        for path in paths.iter() {
            if let Some(path_str) = path.to_str() {
                if path_str != Self::OBFUSCATOR_KEY {
                    return true;
                }
            }
        }
        false
    }

    /// Rebuilds the WAF instance with the current configuration.
    ///
    /// This applies any changes made via [`Self::add_or_update_config`] or
    /// [`Self::remove_config`] and atomically swaps the old instance with the new one.
    ///
    /// # Errors
    /// Returns an error if the WAF instance cannot be built with the current configuration.
    pub fn update(&self) -> anyhow::Result<Arc<Handle>> {
        let mut guard = self.inner.prot_state.lock().unwrap();
        let new_instance = guard
            .builder
            .build()
            .ok_or_else(|| anyhow::anyhow!("Failed to build WAF instance"))?;
        let new_instance = Arc::new(new_instance);
        self.inner.waf_handle.store(new_instance.clone());
        Ok(new_instance)
    }
}

impl Clone for UpdateableWafInstance {
    fn clone(&self) -> Self {
        Self {
            inner: self.inner.clone(),
        }
    }
}

#[cfg(test)]
mod tests {
    use crate::service::updateable_waf::UpdateableWafInstance;
    use libddwaf::object::WafObject;
    use libddwaf::{waf_map, Config, RunResult, RunnableContext};
    use std::{
        sync::{
            atomic::{AtomicBool, Ordering::Relaxed},
            Arc,
        },
        thread::{self, sleep},
        time::Duration,
    };

    const ARACHNI_RULE: &str = r#"
{
   "rules" : [
      {
         "conditions" : [
            {
               "operator" : "match_regex",
               "parameters" : {
                  "inputs" : [
                     {
                        "address" : "server.request.headers.no_cookies",
                        "key_path" : [
                           "user-agent"
                        ]
                     },
                     {
                        "address" : "server.request.body"
                     }
                  ],
                  "regex" : "Arachni"
               }
            }
         ],
         "id" : "arachni_rule",
         "name" : "Block with default action",
         "on_match" : [
            "block"
         ],
         "tags" : {
            "category" : "attack_attempt",
            "type" : "security_scanner"
         }
      }
   ],
   "version" : "2.1"
}
"#;

    const DISABLE_ARACHNI_RULE_PATH: &str = "disable_arachni";
    const DISABLE_ARACHNI_RULE: &str = r#"
{
    "rules_override": [
        {
            "rules_target": [
                {
                    "rule_id": "arachni_rule"
                }
            ],
            "enabled": false
        }
    ]
}
"#;

    #[test]
    fn threaded_updateable_waf_instance() {
        let ruleset: WafObject = serde_json::from_str(ARACHNI_RULE).unwrap();
        let upd_waf = UpdateableWafInstance::new(ruleset, Some(&Config::default()), None).unwrap();

        // add a second rule because it's forbidden to have no rules
        let ruleset2: WafObject = serde_json::from_str(
            &ARACHNI_RULE
                .replace("Arachni", "Inhcara")
                .replace("arachni_rule", "inhcara_rule"),
        )
        .unwrap();
        upd_waf.add_or_update_config("2nd rule", &ruleset2, None);

        assert_eq!(upd_waf.config_paths_count(Some("2nd rule")), 1);
        let paths = upd_waf.config_paths(Some("2nd rule"));
        assert_eq!(paths.len(), 1);

        let update_thread = std::thread::spawn({
            let upd_waf_copy = upd_waf.clone();
            let disable_ruleset: WafObject = serde_json::from_str(DISABLE_ARACHNI_RULE).unwrap();
            move || {
                let mut disable_next = true;
                for _ in 0..10 {
                    sleep(Duration::from_millis(100));
                    if disable_next {
                        let res = upd_waf_copy.add_or_update_config(
                            DISABLE_ARACHNI_RULE_PATH,
                            &disable_ruleset,
                            None,
                        );
                        if !res {
                            panic!("add_or_update_config failed");
                        }
                        println!("disable");
                    } else {
                        upd_waf_copy.remove_config(DISABLE_ARACHNI_RULE_PATH);
                        println!("enable");
                    }
                    upd_waf_copy.update().expect("update did not succeed");
                    disable_next = !disable_next;
                }
            }
        });

        let data = Arc::new(waf_map!((
            "server.request.headers.no_cookies",
            waf_map!(("user-agent", "Arachni"))
        )));

        let stop_signal = &*Box::leak(Box::new(AtomicBool::new(false)));
        let t: Vec<_> = (0..2)
            .map(|_| {
                std::thread::spawn({
                    let upd_waf_copy = upd_waf.clone();
                    let data_copy = data.clone();
                    let mut matches = 0u64;
                    let mut non_matches = 0u64;
                    move || {
                        while !stop_signal.load(Relaxed) {
                            let cur_instance = upd_waf_copy.current();
                            println!("address of instance: {:p}", Arc::as_ptr(&cur_instance));
                            let mut ctx = cur_instance.new_context();
                            let res =
                                ctx.run(data_copy.as_ref().clone(), Duration::from_millis(500));
                            match res {
                                Ok(RunResult::Match(_)) => {
                                    matches += 1;
                                }
                                _ => non_matches += 1,
                            };
                            thread::sleep(Duration::from_millis(20))
                        }
                        (matches, non_matches)
                    }
                })
            })
            .collect::<Vec<_>>();

        update_thread.join().unwrap();
        stop_signal.store(true, Relaxed);

        for jh in t {
            let (matches, non_matches) = jh.join().unwrap();
            println!("positive: {matches}, negative: {non_matches}");
            assert!(matches > 10);
            assert!(non_matches > 10);
        }
    }
}
