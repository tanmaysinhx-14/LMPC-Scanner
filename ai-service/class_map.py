"""Civic issue classes and their base severity scores."""

CIVIC_CLASSES = {
    0: {"name": "pothole", "severity_base": 3, "department": "public_works"},
    1: {"name": "garbage_dump", "severity_base": 2, "department": "sanitation"},
    2: {"name": "Loose wire", "severity_base": 2, "department": "electricity"},
    3: {"name": "Waterlogging", "severity_base": 4, "department": "drainage"},
    4: {"name": "Fallen Tree", "severity_base": 3, "department": "municipal"}
}
