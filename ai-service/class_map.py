"""Civic issue classes and their base severity scores."""

CIVIC_CLASSES = {
    0: {"name": "Pothole Detection", "category": "pothole", "severity_base": 3, "department": "public_works"},
    1: {"name": "garbage", "category": "garbage", "severity_base": 2, "department": "sanitation"},
    2: {"name": "graffiti", "category": "graffiti", "severity_base": 2, "department": "public_works"},
    3: {"name": "Waterlogging", "category": "waterlogging", "severity_base": 4, "department": "drainage"},
    4: {"name": "Fallen Tree", "category": "fallen_tree", "severity_base": 3, "department": "municipal"}
}
