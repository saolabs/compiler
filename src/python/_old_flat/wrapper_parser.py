"""
Parser để đọc file wraper.js và extract nội dung
"""

import re
import os

class WrapperParser:
    def __init__(self):
        self.wrapper_function_content = ""
        self.wrapper_config_content = ""
    
    def parse_wrapper_file(self, file_path="resources/js/templates/wraper.js"):
        """Parse file wraper.js và extract nội dung theo comment đánh dấu"""
        # Reset state trước khi parse
        self.wrapper_function_content = ""
        self.wrapper_config_content = ""

        # Logic tìm kiếm file template:
        # 1. Tìm theo đường dẫn được cung cấp (thường là User Custom Override trong project)
        # 2. Nếu không thấy, tìm file mặc định trong thư mục templates của library (sao/templates/wraper.js)
        
        found_path = None
        
        # 1. Check đường dẫn user cung cấp (relative to Project Root)
        if os.path.exists(file_path):
            found_path = file_path
        else:
            # 2. Check đường dẫn mặc định trong library
            # __file__ = .../sao/scripts/compiler/wrapper_parser.py
            script_dir = os.path.dirname(os.path.abspath(__file__))
            # library_root = .../sao
            # template_path = .../sao/templates/wraper.js
            lib_template_path = os.path.normpath(os.path.join(script_dir, "..", "..", "templates", "wraper.js"))
            
            if os.path.exists(lib_template_path):
                found_path = lib_template_path
            else:
                # 3. Fallback: check alt_path (giữ tương thích cũ)
                alt_path = os.path.normpath(os.path.join(script_dir, "..", "..", file_path))
                if os.path.exists(alt_path):
                    found_path = alt_path

        if not found_path:
            print(f"Warning: Wrapper file not found. Checked: {file_path} and library defaults.")
            return "", ""

        try:
            with open(found_path, 'r', encoding='utf-8') as f:
                content = f.read()
        except Exception as e:
            print(f"Error reading wrapper file: {e}")
            return "", ""

        # Extract function wraper content - từ "// start wrapper" đến "// end wrapper"
        start_marker = "// start wrapper"
        end_marker = "// end wrapper"
        
        start_pos = content.find(start_marker)
        if start_pos != -1:
            start_pos = content.find('\n', start_pos) + 1  # Bỏ qua dòng comment
            end_pos = content.find(end_marker)
            if end_pos != -1:
                self.wrapper_function_content = content[start_pos:end_pos].strip()
            else:
                print("Warning: End wrapper marker not found")
        else:
            print("Warning: Start wrapper marker not found")

        # Extract WRAPPER_CONFIG content - từ "// start wrapper config" đến "// end wrapper config"
        start_config_marker = "// start wrapper config"
        end_config_marker = "// end wrapper config"
        
        start_config_pos = content.find(start_config_marker)
        if start_config_pos != -1:
            start_config_pos = content.find('\n', start_config_pos) + 1  # Bỏ qua dòng comment
            end_config_pos = content.find(end_config_marker)
            if end_config_pos != -1:
                self.wrapper_config_content = content[start_config_pos:end_config_pos].strip()
            else:
                print("Warning: End wrapper config marker not found")
        else:
            print("Warning: Start wrapper config marker not found")

        return self.wrapper_function_content, self.wrapper_config_content
    
    def get_wrapper_function_content(self):
        """Lấy nội dung function wraper"""
        return self.wrapper_function_content
    
    def get_wrapper_config_content(self):
        """Lấy nội dung WRAPPER_CONFIG"""
        return self.wrapper_config_content
