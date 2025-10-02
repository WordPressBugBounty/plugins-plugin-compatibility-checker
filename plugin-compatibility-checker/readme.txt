=== Plugin Compatibility Checker ===
Contributors: CompatShield
Tags: compatibility, plugin checker, php version, security, vulnerabilities
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 6.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Scan and check your plugins for PHP and WordPress compatibility, with vulnerability insights available via the Portal Dashboard.

== Description ==

The **Plugin Compatibility Checker** helps you keep your WordPress site stable and secure by scanning installed plugins for PHP and WordPress version compatibility. It also alerts you about known plugin vulnerabilities (available via the Portal Dashboard).

Free scans are powered by [Tide](https://wptide.org), an open-source project that analyzes plugins and themes for PHP compatibility. This ensures you always have basic compatibility data, even without a license.

With a valid license, you unlock advanced compatibility checks up to PHP 8.4, email notifications, and vulnerability insights via the CompatShield Portal.

### ✨ Key Features ###

* **PHP Compatibility Check** – Scan plugins for PHP compatibility.  
  - Free version: Results come from [Tide](https://wptide.org), up to PHP 8.0  
  - Licensed version: CompatShield Portal results, up to PHP 8.4  

* **Plugin Rescan** – Quickly rescan whenever you install or update plugins.  

* **Email Notifications** – Get notified when scans are completed (licensed only).  

* **Portal Integration** – View full scan results in the CompatShield Portal.  

* **Vulnerability Alerts** – Vulnerability data is only available in the Portal Dashboard (licensed users).  

* **“No Data” Plugins Handling** – Easily identify custom/premium plugins or removed versions not available on WordPress.org.  

### 🔑 Free vs Licensed ###

| Feature                     | Free Version (Tide)             | Licensed Version (Portal)                   |
| --------------------------- | -------------------------------- | ------------------------------------------- |
| PHP Compatibility Check     | Up to PHP 8.0 via Tide           | Up to PHP 8.4 via CompatShield Portal        |
| Vulnerability Scan          | ✖                                | ✅ (in Portal Dashboard only)                |
| Email Notifications         | ✖                                | ✅                                           |
| Portal Dashboard            | ✖                                | Full access (compatibility + vulnerabilities)|
| Custom/Premium Plugins Data | ✖                                | Partially supported (depends on availability)|

== Screenshots ==

1. The main plugin interface with scan results.  
2. Example of plugins listed after rescan.  
3. Fetching the latest results from the Portal.  
4. Viewing compatibility and vulnerabilities (Portal).  

== Installation ==

1. Upload `plugin-compatibility-checker` to the `/wp-content/plugins/` directory.  
2. Activate the plugin through the **Plugins** menu in WordPress.  
3. (Optional) Enter your license key in settings to unlock full features.  

== Frequently Asked Questions ==

= What happens if I don’t activate a license? =  
You will only see compatibility results up to PHP 8.0, powered by [Tide](https://wptide.org). For PHP 8.4 results, email notifications, and vulnerability insights, you need a valid license.  

= Why does a plugin show “No Data”? =  
This usually means the plugin is either custom/premium or its version has been removed from WordPress.org.  

= Do I need to rescan after adding new plugins? =  
Yes, please click **Rescan** to include new plugins in the compatibility check.  
