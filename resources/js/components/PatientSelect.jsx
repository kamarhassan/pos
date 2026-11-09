import React, { useState, useEffect } from "react";
import CreatableSelect from "react-select/creatable";
import axios from "axios";

const PatientSelect = ({ setCustomerId }) => {
    const [patients, setPatients] = useState([]);
    const [selectedPatient, setSelectedPatient] = useState(null);

    // Load patients using their linked legacy customer IDs for order compatibility.
    useEffect(() => {
      axios.get("/admin/get/customers").then((response) => {
            const patientOptions = response?.data?.map((patient) => ({
                value: patient.id,
                label: patient.name,
            }));
            setPatients(patientOptions);
            setSelectedPatient(
                patientOptions.find(
                    (patient) => patient.label.toLowerCase() === "no customer"
                ) || null
            );
        });
    }, []);
  useEffect(() => {
    setCustomerId(selectedPatient?.value);
  }, [selectedPatient]);

    const handleCreatePatient = (inputValue) => {
        axios
            .post("/admin/create/customers", { name: inputValue })
            .then((response) => {
                const newPatient = response.data;
                const newOption = {
                    value: newPatient.id,
                    label: newPatient.name,
                };
                setPatients((prev) => [newOption,...prev]);
                setSelectedPatient(newOption);
            })
            .catch((error) => {
                console.error("Error creating patient:", error);
            });
    };

    const handleChange = (newValue) => {
        setSelectedPatient(newValue);
    };

    return (
        <CreatableSelect
            isClearable
            options={patients}
            onChange={handleChange}
            onCreateOption={handleCreatePatient}
            value={selectedPatient}
            placeholder="Select or create patient"
        />
    );
};

export default PatientSelect;
