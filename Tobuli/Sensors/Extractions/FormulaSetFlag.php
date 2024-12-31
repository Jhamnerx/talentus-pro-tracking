<?php


namespace Tobuli\Sensors\Extractions;


class FormulaSetFlag extends Formula
{
    const PLACEHOLDER = "[value]";

    /**
     * @var string
     */
    protected $formula;

    /**
     * @var array
     */
    protected $setflags;

    public function __construct(string $formula, array $setflags)
    {
        $formula = $this->replaceFormula($formula, $setflags);
        $this->setflags = $setflags;

        parent::__construct($formula);
    }

    public function parse($value)
    {
        $equation = $this->formula;

        foreach ($this->setflags as $place => $setflag) {
            $equation = str_replace(
                "[$place]",
                substr($value, $setflag['start'], $setflag['count']),
                $equation
            );
        }

        return $this->solve($equation);
    }

    protected function replaceFormula($formula, $setflags)
    {
        foreach ($setflags as $place => $setflag) {
            $formula = str_replace($setflag['place'], "[$place]", $formula);
        }

        return $formula;
    }

    static public function resolveSetflag($formula)
    {
        $groups = \Tobuli\Helpers\SetFlag::multiCrop($formula);

        $data = [];

        foreach ($groups as $i => $group) {
            $data["[value{$i}]"] = $group;
        }

        return $data;
    }
}