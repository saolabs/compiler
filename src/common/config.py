"""
Configuration file for Python Blade Compiler (Library Version)
"""
import os
import json

class CompilerConfig:
    """Configuration class for Blade compiler paths and settings"""
    
    def __init__(self, config_file=None):
        # 1. Project Root: Taken from environment variable (Passed by CLI wrapper)
        self.project_root = os.environ.get('SAO_PROJECT_ROOT')
        if not self.project_root:
             # Fallback for dev/testing in library itself
             self.project_root = os.getcwd()

        # 2. Library Root: Taken from environment variable (Passed by CLI wrapper)
        self.lib_root = os.environ.get('SAO_LIB_ROOT')
        if not self.lib_root:
             # Fallback relative to this file
             self.lib_root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

        # Load build config from PROJECT root (User's project)
        # It's usually build.config.json or compiler.config.json
        # We try to find build.config.json first as it is the new standard
        self.build_config_file = os.path.join(self.project_root, 'build.config.json')
        self.build_config_data = {}

        if os.path.exists(self.build_config_file):
            try:
                with open(self.build_config_file, 'r', encoding='utf-8') as f:
                    self.build_config_data = json.load(f)
            except Exception as e:
                 print(f"Warning: Could not load build.config.json: {e}")

        # Default paths
        self.views_input_path = os.path.join(self.project_root, 'resources/views')
        # Default js output: resources/js (The compiler will append /views or /config internally)
        self.js_input_path = os.path.join(self.project_root, 'resources/js') 
        
        # Template path (Internal to Library)
        self.wrapper_template_path = os.path.join(self.lib_root, 'templates')

        # Files
        self.view_templates_file = 'templates.js'
        
        # Other settings from config or defaults
        self.config_data = self.build_config_data # Alias for compatibility

    def get_wrapper_template_path(self):
        """Get path to the wrapper template inside the library"""
        return self.wrapper_template_path
        
    def get_build_directories(self):
        """Get list of directories to build (relative to views root)"""
        # If 'contexts' is defined in build.config.json, we don't use this old method usually, 
        # but for compatibility or simple usage:
        return []

    def get_views_path(self, scope=None):
        if scope:
            return os.path.join(self.views_input_path, scope)
        return self.views_input_path

    def get_js_input_path(self):
        return self.js_input_path

# Constants for backward compatibility
JS_FUNCTION_PREFIX = "App.Helper"
HTML_ATTR_PREFIX = "data-"
SPA_YIELD_ATTR_PREFIX = "data-yield-attr"
SPA_YIELD_SUBSCRIBE_KEY_PREFIX = "data-yield-key"
SPA_YIELD_SUBSCRIBE_TARGET_PREFIX = "data-yield-target"
SPA_YIELD_SUBSCRIBE_ATTR_PREFIX = "data-yield-attr"
SPA_YIELD_CONTENT_PREFIX = "data-yield-content"
SPA_YIELD_CHILDREN_PREFIX = "data-yield-children"
SPA_STATECHANGE_PREFIX = "data-statechange-"
APP_VIEW_NAMESPACE = "App.View"
APP_HELPER_NAMESPACE = "App.Helper"

class ViewConfig:
    VIEW_FUNCTIONS = [
        'generateViewId', 'execute', 'evaluate', 'escString', 'text', 'templateToDom',
        'view', 'loadView', 'renderView', 'include', 'includeIf', 'extendView',
        'setSuperViewPath', 'addViewEngine', 'callViewEngineMounted',
        'startWrapper', 'endWrapper', 'registerSubscribe',
        'section', 'yield', 'yieldContent', 'renderSections', 'hasSection',
        'getChangedSections', 'resetChangedSections', 'isChangedSection', 'emitChangedSections',
        'push', 'stack', 'once', 'route', 'on', 'off', 'emit',
        'init', 'setApp', 'setContainer', 'clearOldRendering',
        'isAuth', 'can', 'cannot', 'hasError', 'firstError', 'csrfToken',
        'foreach', 'foreachTemplate'
    ]

    @classmethod
    def is_view_function(cls, function_name):
        return function_name in cls.VIEW_FUNCTIONS

    @classmethod
    def is_helper_function(cls, function_name):
        return not cls.is_view_function(function_name)

    @classmethod
    def get_function_source(cls, function_name):
        return 'View' if cls.is_view_function(function_name) else 'Helper'

config = CompilerConfig()
