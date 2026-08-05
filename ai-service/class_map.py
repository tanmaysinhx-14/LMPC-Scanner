"""Civic issue classes and their base severity scores."""

CIVIC_CLASSES = {
    0: {"name": "pothole", "severity_base": 3, "department": "public_works"},
    1: {"name": "garbage_dump", "severity_base": 2, "department": "sanitation"},
    2: {"name": "broken_streetlight", "severity_base": 2, "department": "electricity"},
    3: {"name": "waterlogging", "severity_base": 4, "department": "drainage"},
    4: {"name": "road_damage", "severity_base": 3, "department": "public_works"},
    5: {"name": "encroachment", "severity_base": 2, "department": "municipal"},
    6: {"name": "graffiti", "severity_base": 1, "department": "sanitation"},
    7: {"name": "open_drain", "severity_base": 4, "department": "drainage"},
}
